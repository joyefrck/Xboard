package main

import (
    "crypto/tls"
    "fmt"
    "net/http"
    "os"
    "strings"
)

func main() {
    payload := strings.Repeat("verified-hybrid-reality-payload\n", 4096)
    handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        fmt.Println("TARGET", r.URL.Path, "tls", r.TLS != nil)
        w.Header().Set("Content-Length", fmt.Sprint(len(payload)))
        fmt.Fprint(w, payload)
    })
    go http.ListenAndServe("127.0.0.1:18080", handler)
    server := &http.Server{
        Addr: "127.0.0.1:19443",
        Handler: handler,
        TLSConfig: &tls.Config{
            MinVersion: tls.VersionTLS13,
            CurvePreferences: []tls.CurveID{tls.X25519MLKEM768},
            NextProtos: []string{"h2", "http/1.1"},
        },
    }
    if err := server.ListenAndServeTLS(os.Args[1], os.Args[2]); err != nil {
        panic(err)
    }
}
