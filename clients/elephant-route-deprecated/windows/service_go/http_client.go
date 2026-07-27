// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"time"
)

const clashAPIBaseURL = "http://127.0.0.1:9090"

type clashController interface {
	URLTest(context.Context, string) (int, error)
	SelectOutbound(context.Context, string, string) error
}

type localClashController struct {
	client *http.Client
}

func newLocalClashController() *localClashController {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = nil
	transport.DialContext = (&net.Dialer{
		Timeout:   2 * time.Second,
		KeepAlive: 30 * time.Second,
	}).DialContext
	return &localClashController{
		client: &http.Client{
			Transport: transport,
			Timeout:   4 * time.Second,
		},
	}
}

func (controller *localClashController) URLTest(ctx context.Context, group string) (int, error) {
	path := "/proxies/" + url.PathEscape(group) +
		"/delay?url=https%3A%2F%2Fwww.gstatic.com%2Fgenerate_204&timeout=3000"
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, clashAPIBaseURL+path, nil)
	if err != nil {
		return -1, err
	}
	response, err := controller.client.Do(request)
	if err != nil {
		return -1, err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return -1, fmt.Errorf("Clash API status %d", response.StatusCode)
	}
	var result struct {
		Delay int `json:"delay"`
	}
	decoder := json.NewDecoder(io.LimitReader(response.Body, 64*1024))
	if err = decoder.Decode(&result); err != nil {
		return -1, err
	}
	return result.Delay, nil
}

func (controller *localClashController) SelectOutbound(
	ctx context.Context,
	group string,
	outbound string,
) error {
	body, err := json.Marshal(map[string]string{"name": outbound})
	if err != nil {
		return err
	}
	path := "/proxies/" + url.PathEscape(group)
	request, err := http.NewRequestWithContext(
		ctx,
		http.MethodPut,
		clashAPIBaseURL+path,
		bytes.NewReader(body),
	)
	if err != nil {
		return err
	}
	request.Header.Set("Content-Type", "application/json")
	response, err := controller.client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return errors.New("Clash API rejected outbound selection")
	}
	return nil
}

type dispatcher struct {
	manager  *coreManager
	profiles profileProvider
	clash    clashController
}

func newDispatcher(manager *coreManager, profiles profileProvider, clash clashController) *dispatcher {
	return &dispatcher{manager: manager, profiles: profiles, clash: clash}
}

func (dispatcher *dispatcher) handle(ctx context.Context, request request) response {
	switch request.Method {
	case "getStatus":
		return dispatcher.manager.snapshot().response()
	case "getNetworkProfile":
		profile, err := dispatcher.profiles.Profile()
		if err != nil {
			return errorResponse(
				"default_interface_missing",
				"No usable physical IPv4 default interface or TUN subnet was found.",
			)
		}
		return response{
			Status:           "ready",
			DefaultInterface: profile.DefaultInterface,
			TunIPv4Address:   profile.TunIPv4Address,
			StrictRoute:      profile.StrictRoute,
		}
	case "start", "prepareSpeedTest":
		if request.Config == "" {
			return errorResponse("config_invalid", "Windows TUN configuration is missing.")
		}
		if _, err := dispatcher.profiles.Profile(); err != nil {
			return errorResponse(
				"default_interface_missing",
				"No usable physical IPv4 default interface or TUN subnet was found.",
			)
		}
		return dispatcher.manager.start(ctx, []byte(request.Config), request.Method == "prepareSpeedTest")
	case "stop":
		return dispatcher.manager.stop()
	case "stopSpeedTest":
		return dispatcher.manager.stopSpeedTest()
	case "urlTest":
		if request.GroupTag == "" {
			return errorResponse("config_invalid", "Outbound group is missing.")
		}
		delay, err := dispatcher.clash.URLTest(ctx, request.GroupTag)
		if err != nil {
			return errorResponse("core_start_failed", "sing-box URL test failed.")
		}
		return response{Status: statusConnected, Delay: &delay}
	case "selectOutbound":
		if request.GroupTag == "" || request.OutboundTag == "" {
			return errorResponse("config_invalid", "Outbound selection is missing.")
		}
		if err := dispatcher.clash.SelectOutbound(ctx, request.GroupTag, request.OutboundTag); err != nil {
			return errorResponse("core_start_failed", "sing-box rejected outbound selection.")
		}
		return dispatcher.manager.snapshot().response()
	default:
		return errorResponse("protocol_error", "Windows service method is not allowed.")
	}
}
