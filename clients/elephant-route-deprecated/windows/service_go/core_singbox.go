// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/experimental/deprecated"
	"github.com/sagernet/sing-box/include"
	"github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	"github.com/sagernet/sing/common/json"
	"github.com/sagernet/sing/service"
)

const bundledSingBoxVersion = "sing-box 1.12.25"

type singBoxFactory struct{}

type singBoxInstance struct {
	box *box.Box
}

func (instance *singBoxInstance) Start() error {
	return instance.box.Start()
}

func (instance *singBoxInstance) Close() error {
	return instance.box.Close()
}

func (instance *singBoxInstance) LatencyProbe() latencyProbeFunc {
	probe := newConnectionLatencyProbe(
		func(tag string) (latencyDialer, bool) {
			outbound, found := instance.box.Outbound().Outbound(tag)
			if !found {
				return nil, false
			}
			return outbound, true
		},
	)
	return probe.Probe
}

func (singBoxFactory) Version() string {
	return bundledSingBoxVersion
}

func (singBoxFactory) New(
	parent context.Context,
	config []byte,
	runtimeDirectory string,
) (coreInstance, error) {
	setSingBoxCompatibilityEnvironment()
	coreContext := include.Context(service.ContextWith(
		parent,
		deprecated.NewStderrManager(log.StdLogger()),
	))
	options, err := json.UnmarshalExtendedContext[option.Options](coreContext, config)
	if err != nil {
		return nil, fmt.Errorf("decode configuration: %w", err)
	}
	if options.Log == nil {
		options.Log = &option.LogOptions{}
	}
	options.Log.Output = filepath.Join(runtimeDirectory, "sing-box.log")
	options.Log.DisableColor = true
	instance, err := box.New(box.Options{
		Context: coreContext,
		Options: options,
	})
	if err != nil {
		return nil, fmt.Errorf("create service: %w", err)
	}
	return &singBoxInstance{box: instance}, nil
}

func setSingBoxCompatibilityEnvironment() {
	for name, value := range map[string]string{
		"ENABLE_DEPRECATED_SPECIAL_OUTBOUNDS":  "true",
		"ENABLE_DEPRECATED_LEGACY_DNS_SERVERS": "true",
		"ENABLE_DEPRECATED_TUN_ADDRESS_X":      "true",
	} {
		_ = os.Setenv(name, value)
	}
}
