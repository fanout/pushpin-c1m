# Pushpin and 1 million connections

[Pushpin](https://github.com/fanout/pushpin) is a publish-subscribe server, supporting HTTP and WebSocket connections. This repository contains instructions and configuration files for running a single instance capable of handling one million connections simultaneously.

<p align="center">
  <img src="https://pushpin.org/image/pushpin-c1m-graph.png" alt="graph of 1m connections"/>
</p>

## Requirements

* A Linux server with at least 8 CPU cores and 32GB memory. For example, DigitalOcean's `g-8vcpu-32gb` droplet type.
* Ubuntu 20.04. However, the instructions should be easy to adapt to other distributions.

## Limitations

For now, the goal is to handle lots of connections that are mostly quiet, using the HTTP transport.

A valid use-case for lots of connections with limited throughput could be a chat room service with 10,000 rooms, each with 100 participants. With the default `message_hwm` (25,000), up to 250 rooms could generate a message at the same time without drops or extra latency.

If you need greater throughput, run more than one instance.

## Background

Vertical scalability was not always a priority for the Pushpin project. This is because Pushpin introduced a new way of building push APIs, and we wanted to ensure the core functionality was correct before optimizing. Further, Pushpin was already horizontally scalable. However, performance is important in the world of push & publish-subscribe, and it's about time we made it a priority. Developers like to see lots of connections and message throughput.

Over the past year or so we've been refactoring Pushpin from the ground up for high performance. The first major milestone was [rewriting Pushpin's connection manager](https://blog.fanout.io/2020/08/11/rewriting-pushpins-connection-manager-in-rust/) in optimized Rust. This enabled the support of millions of concurrent TCP connections. Next, Pushpin's pub-sub handler (C++) was optimized to match. This mostly involved working around scalability issues in Qt regarding timers and signals/slots. Notably, QTimer was replaced with the timer wheel algorithm used by the Rust-based connection manager, called from C++ using FFI bindings.

Pushpin now handles HTTP connections very efficiently. In the future, we'll look at improving the performance of WebSockets, as well as throughput in general.

## Server setup

### Backend

First we'll set up a backend handler. To keep it simple, we'll do it statelessly with PHP.

Install packages:

```sh
sudo apt install libapache2-mod-php
```

Then save the following handler code as `/var/www/html/stream.php`:

```php
<?php

$topic = $_GET["topic"];

if ($topic) {
    header('Content-Type: text/event-stream');
    header('Grip-Hold: stream');
    header('Grip-Channel: ' . $topic);
    header('Grip-Keep-Alive: :\n\n; format=cstring; timeout=55');

    print "event: message\ndata: stream open\n\n";
} else {
    header('Content-Type: text/plain');

    print "missing parameter: topic\n";
}

?>
```

This code tells Pushpin to respond with an SSE event and hold the connection open, subscribing it to a channel based on a query parameter.

### OS limits

Set the following sysctl config:

```
fs.file-max=1010000
fs.nr_open=1010000
net.ipv4.ip_local_port_range=10240 65000
net.ipv4.tcp_mem=10000000 10000000 10000000
net.ipv4.tcp_rmem=1024 4096 16384
net.ipv4.tcp_wmem=1024 4096 16384
net.core.rmem_max=16384
net.core.wmem_max=16384
```

Ensure the settings are applied, by rebooting if necessary.

### Pushpin

Install:

```sh
echo deb https://fanout.jfrog.io/artifactory/debian fanout-focal main \
  | sudo tee /etc/apt/sources.list.d/fanout.list
sudo apt-key adv --keyserver hkp://keyserver.ubuntu.com:80 \
  --recv-keys EA01C1E777F95324
sudo apt update
sudo apt install pushpin
```

Edit `/lib/systemd/system/pushpin.service` and add `LimitNOFILE`:

```
[Service]
...
LimitNOFILE=1010000
```

Make sure systemd sees it:

```sh
sudo systemctl daemon-reload
```

In `/etc/pushpin/pushpin.conf`, ensure the following fields are set:

```
http_port=8000,8001,8002,8003,8004,8005,8006,8007
client_buffer_size=4096
client_maxconn=1001000
```

The extra listen ports help avoid port exhaustion when running benchmarks.

Edit `/etc/pushpin/routes` to route requests to Apache/PHP:

```
* localhost:80
```

Restart the server:

```sh
sudo service pushpin restart
```

### Test

It should now be possible to make a request that hangs open:

```sh
$ curl http://server:8000/stream.php?topic=test
event: message
data: stream opened
...
```

Data can be published to it:

```sh
pushpin-publish --no-eol test $'event: message\ndata: hello world\n\n'
```

The client will receive:

```
event: message
data: hello world
```

## Benchmarking

For our benchmarks we used TCPKali running on 4 DigitalOcean droplets of type `c-16`.

Each instance created 250k connections, running a command similar to the following, where `server` is replaced with the IP address of the Pushpin server, and `cbench-1` is changed to a unique string for each instance.

```sh
tcpkali -c 250000 --connect-rate 200 -T 2h --message-marker --no-source-bind \
  --statsd --statsd-namespace cbench-1 --statsd-latency-window 10s -e -1 \
  'GET /stream.php?topic=group-\{connection.uid%1000} HTTP/1.1\r\nHost: example.com\r\n\r\n' \
  server:8000 server:8001 server:8002 server:8003 \
  server:8004 server:8005 server:8006 server:8007
```

TCPKali can output metrics to statsd, which we used to create a graph in Grafana.

On the Pushpin server, metrics can be examined by connecting to Pushpin's stats socket using `tools/monitorstats.py` from the Pushpin repository:

```sh
$ python3 monitorstats.py ipc:///var/run/pushpin/pushpin-stats report
...
report {'from': 'pushpin-handler_856', 'minutes': 166216, 'http-response-sent': 0,
 'sent': 0, 'connections': 1000004, 'duration': 9998, 'received': 0}
```

(output wrapped for readability)

Between TCPKali's metrics and Pushpin's metrics, we can confirm indeed that 1 million connections are established.

Even with a million clients connected, Pushpin remains performant:

```
$ time curl http://server:8000/
...

real    0m0.035s
user    0m0.009s
sys     0m0.013s
```