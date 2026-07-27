// SPDX-License-Identifier: GPL-3.0-or-later

package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
)

var trustedRuntimeAssets = []string{"geoip-cn.srs", "geosite-cn.srs"}

func prepareRuntimeFiles(assetDirectory, runtimeDirectory string, config []byte) error {
	if len(config) == 0 || len(config) > maxConfigBytes {
		return errors.New("configuration size is invalid")
	}
	if err := os.MkdirAll(runtimeDirectory, 0o755); err != nil {
		return fmt.Errorf("create runtime directory: %w", err)
	}
	for _, name := range trustedRuntimeAssets {
		source := filepath.Join(assetDirectory, name)
		destination := filepath.Join(runtimeDirectory, name)
		if err := copyRegularFile(source, destination); err != nil {
			return fmt.Errorf("copy runtime asset: %w", err)
		}
	}
	return atomicWriteFile(filepath.Join(runtimeDirectory, "config.json"), config, 0o600)
}

func copyRegularFile(source, destination string) error {
	info, err := os.Lstat(source)
	if err != nil {
		return err
	}
	if !info.Mode().IsRegular() {
		return errors.New("runtime asset is not a regular file")
	}
	input, err := os.Open(source)
	if err != nil {
		return err
	}
	defer input.Close()
	temp, err := os.CreateTemp(filepath.Dir(destination), ".asset-*")
	if err != nil {
		return err
	}
	tempName := temp.Name()
	defer os.Remove(tempName)
	if _, err = io.Copy(temp, input); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Sync(); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Close(); err != nil {
		return err
	}
	_ = os.Remove(destination)
	return os.Rename(tempName, destination)
}

func atomicWriteFile(path string, content []byte, mode os.FileMode) error {
	temp, err := os.CreateTemp(filepath.Dir(path), ".config-*")
	if err != nil {
		return err
	}
	tempName := temp.Name()
	defer os.Remove(tempName)
	if err = temp.Chmod(mode); err != nil {
		temp.Close()
		return err
	}
	if _, err = temp.Write(content); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Sync(); err != nil {
		temp.Close()
		return err
	}
	if err = temp.Close(); err != nil {
		return err
	}
	_ = os.Remove(path)
	return os.Rename(tempName, path)
}
