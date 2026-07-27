// SPDX-License-Identifier: GPL-3.0-or-later

//go:build windows

package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"sync"
	"sync/atomic"
	"time"

	"github.com/Microsoft/go-winio"
)

const (
	servicePipeName = `\\.\pipe\ElephantNetworkService.v1`
	servicePipeSDDL = "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GRGW;;;IU)"
)

type pipeServer struct {
	dispatcher    *dispatcher
	listener      net.Listener
	stopOnce      sync.Once
	lastHeartbeat atomic.Int64
	context       context.Context
	cancel        context.CancelFunc
}

func newPipeServer(dispatcher *dispatcher) *pipeServer {
	serverContext, cancel := context.WithCancel(context.Background())
	server := &pipeServer{
		dispatcher: dispatcher,
		context:    serverContext,
		cancel:     cancel,
	}
	server.lastHeartbeat.Store(time.Now().UnixNano())
	return server
}

func (server *pipeServer) listen() error {
	listener, err := winio.ListenPipe(servicePipeName, &winio.PipeConfig{
		SecurityDescriptor: servicePipeSDDL,
		MessageMode:        false,
		InputBufferSize:    64 * 1024,
		OutputBufferSize:   64 * 1024,
	})
	if err != nil {
		return err
	}
	server.listener = listener
	return nil
}

func (server *pipeServer) serve(done chan<- error) {
	for {
		connection, err := server.listener.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				done <- nil
			} else {
				done <- err
			}
			return
		}
		go server.handleConnection(connection)
	}
}

func (server *pipeServer) handleConnection(connection net.Conn) {
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(70 * time.Second))
	payload, err := readFrame(connection)
	var result response
	if err != nil {
		result = safeProtocolError(err)
	} else {
		parsed, decodeError := decodeRequest(payload)
		if decodeError != nil {
			result = safeProtocolError(decodeError)
		} else {
			server.lastHeartbeat.Store(time.Now().UnixNano())
			result = server.dispatcher.handle(server.context, parsed)
		}
	}
	encoded, err := json.Marshal(result)
	if err == nil {
		_ = writeFrame(connection, encoded)
	}
}

func (server *pipeServer) close() {
	server.stopOnce.Do(func() {
		server.cancel()
		if server.listener != nil {
			_ = server.listener.Close()
		}
	})
}

func readFrame(reader io.Reader) ([]byte, error) {
	var length uint32
	if err := binary.Read(reader, binary.LittleEndian, &length); err != nil {
		return nil, err
	}
	if length == 0 || length > maxMessageBytes {
		return nil, errMessageTooLarge
	}
	payload := make([]byte, length)
	if _, err := io.ReadFull(reader, payload); err != nil {
		return nil, err
	}
	return payload, nil
}

func writeFrame(writer io.Writer, payload []byte) error {
	if len(payload) > maxMessageBytes {
		return errMessageTooLarge
	}
	if err := binary.Write(writer, binary.LittleEndian, uint32(len(payload))); err != nil {
		return err
	}
	_, err := writer.Write(payload)
	return err
}
