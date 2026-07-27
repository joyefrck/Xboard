// SPDX-License-Identifier: GPL-3.0-or-later

//go:build windows

package main

import (
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/debug"
)

const (
	serviceName      = "ElephantNetworkService"
	clientGoneAfter  = 15 * time.Second
	coreStartTimeout = 60 * time.Second
)

type windowsService struct {
	manager  *coreManager
	server   *pipeServer
	logFile  *os.File
	stopOnce sync.Once
}

func runService() error {
	host, err := newWindowsService()
	if err != nil {
		return err
	}
	defer host.close()
	isService, err := svc.IsWindowsService()
	if err != nil {
		return err
	}
	if isService {
		return svc.Run(serviceName, host)
	}
	return debug.Run(serviceName, host)
}

func newWindowsService() (*windowsService, error) {
	executable, err := os.Executable()
	if err != nil {
		return nil, err
	}
	installDirectory := filepath.Dir(executable)
	programData := os.Getenv("ProgramData")
	if programData == "" {
		programData = `C:\ProgramData`
	}
	runtimeDirectory := filepath.Join(programData, "ElephantNetwork", "runtime")
	if err = os.MkdirAll(runtimeDirectory, 0o755); err != nil {
		return nil, err
	}
	if err = os.Chdir(runtimeDirectory); err != nil {
		return nil, err
	}
	logFile, err := os.OpenFile(
		filepath.Join(runtimeDirectory, "service.log"),
		os.O_CREATE|os.O_APPEND|os.O_WRONLY,
		0o600,
	)
	if err != nil {
		return nil, err
	}
	manager := newCoreManager(
		singBoxFactory{},
		coreStartTimeout,
		filepath.Join(installDirectory, "data", "flutter_assets", "assets", "srs"),
		runtimeDirectory,
		newLifecycleLogger(logFile),
	)
	dispatcher := newDispatcher(manager, windowsProfileProvider{}, newLocalClashController())
	return &windowsService{
		manager: manager,
		server:  newPipeServer(dispatcher),
		logFile: logFile,
	}, nil
}

func (service *windowsService) Execute(
	_ []string,
	requests <-chan svc.ChangeRequest,
	changes chan<- svc.Status,
) (bool, uint32) {
	changes <- svc.Status{State: svc.StartPending}
	if err := service.server.listen(); err != nil {
		return false, 1
	}
	pipeDone := make(chan error, 1)
	go service.server.serve(pipeDone)
	watchdogStop := make(chan struct{})
	go service.watchdog(watchdogStop)

	changes <- svc.Status{
		State:   svc.Running,
		Accepts: svc.AcceptStop | svc.AcceptShutdown,
	}
	for {
		select {
		case request := <-requests:
			switch request.Cmd {
			case svc.Interrogate:
				changes <- request.CurrentStatus
			case svc.Stop, svc.Shutdown:
				changes <- svc.Status{State: svc.StopPending}
				close(watchdogStop)
				service.close()
				return false, 0
			}
		case err := <-pipeDone:
			close(watchdogStop)
			service.close()
			if err != nil && !errors.Is(err, os.ErrClosed) {
				return false, 2
			}
			return false, 0
		}
	}
}

func (service *windowsService) watchdog(stop <-chan struct{}) {
	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ticker.C:
			last := time.Unix(0, service.server.lastHeartbeat.Load())
			if service.manager.isConnected() && time.Since(last) > clientGoneAfter {
				service.manager.stop()
			}
		case <-stop:
			return
		}
	}
}

func (service *windowsService) close() {
	service.stopOnce.Do(func() {
		service.server.close()
		service.manager.stop()
		if service.logFile != nil {
			_ = service.logFile.Close()
		}
	})
}
