package com.elephantroute

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.util.Collections
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

class AndroidConnectionProbeManagerTest {
    @Test
    fun `two requests reuse one persistent proxy connection`() {
        val remotePorts = Collections.synchronizedList(mutableListOf<Int>())
        val requestsReceived = CountDownLatch(2)
        val server = ServerSocket(0, 4, InetAddress.getByName("127.0.0.1"))
        val executor = Executors.newCachedThreadPool()
        executor.execute {
            while (requestsReceived.count > 0) {
                try {
                    val socket = server.accept()
                    executor.execute {
                        handleProxyConnection(socket, remotePorts, requestsReceived)
                    }
                } catch (_: Exception) {
                    return@execute
                }
            }
        }

        try {
            val result = AndroidConnectionProbeManager().probe(
                sessionId = "test-session",
                proxyPort = server.localPort,
                testUrl = "http://example.com/generate_204",
                timeoutMs = 2_000,
            )

            assertTrue(requestsReceived.await(1, TimeUnit.SECONDS))
            assertEquals(2, result.attempts.size)
            assertTrue(result.attempts.all { it >= 0 })
            assertEquals(1, result.connectionCount)
            assertEquals(2, remotePorts.size)
            assertEquals(1, remotePorts.toSet().size)
        } finally {
            server.close()
            executor.shutdownNow()
        }
    }

    private fun handleProxyConnection(
        socket: Socket,
        remotePorts: MutableList<Int>,
        requestsReceived: CountDownLatch,
    ) {
        socket.use {
            socket.soTimeout = 2_000
            val reader = BufferedReader(InputStreamReader(socket.getInputStream()))
            val output = socket.getOutputStream()
            while (requestsReceived.count > 0) {
                val requestLine = try {
                    reader.readLine()
                } catch (_: Exception) {
                    return
                } ?: return
                if (requestLine.isBlank()) continue
                while (reader.readLine()?.isNotEmpty() == true) {
                    // Consume request headers.
                }
                remotePorts += socket.port
                output.write(
                    "HTTP/1.1 204 No Content\r\n".toByteArray() +
                        "Content-Length: 0\r\n".toByteArray() +
                        "Connection: keep-alive\r\n\r\n".toByteArray(),
                )
                output.flush()
                requestsReceived.countDown()
            }
        }
    }
}
