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
            assertTrue(result.attempts.all { it > 0 })
            assertEquals(1, result.connectionCount)
            assertEquals(listOf(204, 204), result.httpStatusCodes)
            assertEquals(null, result.failureKind)
            assertEquals(2, remotePorts.size)
            assertEquals(1, remotePorts.toSet().size)
        } finally {
            server.close()
            executor.shutdownNow()
        }
    }

    @Test
    fun `non successful HTTP responses are reported as HTTP errors`() {
        val result = probeWithHttpStatus(503)

        assertEquals(listOf(-1, -1), result.attempts)
        assertEquals(listOf(503, 503), result.httpStatusCodes)
        assertEquals("httpError", result.failureKind)
    }

    @Test
    fun `proxy connection failures are reported as transport errors`() {
        val unavailablePort = ServerSocket(0).use { it.localPort }

        val result = AndroidConnectionProbeManager().probe(
            sessionId = "transport-session",
            proxyPort = unavailablePort,
            testUrl = "http://example.com/generate_204",
            timeoutMs = 1_000,
        )

        assertEquals(listOf(-1, -1), result.attempts)
        assertEquals(listOf(0, 0), result.httpStatusCodes)
        assertEquals("transportError", result.failureKind)
    }

    @Test
    fun `deadline exhaustion is reported as timeout`() {
        val server = ServerSocket(0, 1, InetAddress.getByName("127.0.0.1"))
        val releaseConnection = CountDownLatch(1)
        val executor = Executors.newSingleThreadExecutor()
        executor.execute {
            try {
                server.accept().use {
                    releaseConnection.await(2, TimeUnit.SECONDS)
                }
            } catch (_: Exception) {
                // The test closes the socket after the probe reaches its deadline.
            }
        }

        try {
            val result = AndroidConnectionProbeManager().probe(
                sessionId = "timeout-session",
                proxyPort = server.localPort,
                testUrl = "http://example.com/generate_204",
                timeoutMs = 150,
            )

            assertEquals(listOf(-1, -1), result.attempts)
            assertEquals(listOf(0, 0), result.httpStatusCodes)
            assertEquals("timeout", result.failureKind)
        } finally {
            releaseConnection.countDown()
            server.close()
            executor.shutdownNow()
        }
    }

    @Test
    fun `cancelled sessions are reported as cancelled without connecting`() {
        val manager = AndroidConnectionProbeManager()
        manager.cancel("cancelled-session")

        val result = manager.probe(
            sessionId = "cancelled-session",
            proxyPort = 31001,
            testUrl = "http://example.com/generate_204",
            timeoutMs = 1_000,
        )

        assertEquals(listOf(-1, -1), result.attempts)
        assertEquals(listOf(0, 0), result.httpStatusCodes)
        assertEquals("cancelled", result.failureKind)
    }

    @Test
    fun `node keys are stable hashes and never contain the raw tag`() {
        val rawTag = "Tokyo AnyTLS user@example.com"

        val first = AndroidConnectionProbeManager.nodeKey(rawTag)
        val second = AndroidConnectionProbeManager.nodeKey(rawTag)

        assertEquals(first, second)
        assertTrue(first.matches(Regex("[0-9a-f]{12}")))
        assertTrue(!first.contains(rawTag))
    }

    private fun probeWithHttpStatus(statusCode: Int): AndroidConnectionProbeResult {
        val requestsReceived = CountDownLatch(2)
        val server = ServerSocket(0, 2, InetAddress.getByName("127.0.0.1"))
        val executor = Executors.newCachedThreadPool()
        executor.execute {
            while (requestsReceived.count > 0) {
                try {
                    val socket = server.accept()
                    executor.execute {
                        socket.use {
                            socket.soTimeout = 2_000
                            val reader = BufferedReader(InputStreamReader(socket.getInputStream()))
                            val output = socket.getOutputStream()
                            while (requestsReceived.count > 0) {
                                val requestLine = reader.readLine() ?: return@use
                                if (requestLine.isBlank()) continue
                                while (reader.readLine()?.isNotEmpty() == true) {
                                    // Consume request headers.
                                }
                                output.write(
                                    "HTTP/1.1 $statusCode Test\r\n".toByteArray() +
                                        "Content-Length: 0\r\n".toByteArray() +
                                        "Connection: keep-alive\r\n\r\n".toByteArray(),
                                )
                                output.flush()
                                requestsReceived.countDown()
                            }
                        }
                    }
                } catch (_: Exception) {
                    return@execute
                }
            }
        }

        return try {
            AndroidConnectionProbeManager().probe(
                sessionId = "http-$statusCode-session",
                proxyPort = server.localPort,
                testUrl = "http://example.com/generate_204",
                timeoutMs = 2_000,
            )
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
