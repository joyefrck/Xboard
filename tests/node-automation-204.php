<?php
// Disposable TLS endpoint for the real proxy test; no application or .env loaded.
$context=stream_context_create(['ssl'=>['local_cert'=>$argv[1],'local_pk'=>$argv[2]]]);
$server=stream_socket_server('tls://127.0.0.1:'.$argv[3],$errno,$error,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,$context);
if(!$server)exit(1);
while(true){
    $client=@stream_socket_accept($server,1);
    if(!$client)continue;
    stream_set_timeout($client,2);
    while(($line=fgets($client))!==false&&trim($line)!==''){}
    fwrite($client,"HTTP/1.1 204 No Content\r\nConnection: close\r\nCache-Control: no-store\r\n\r\n");
    fclose($client);
}
