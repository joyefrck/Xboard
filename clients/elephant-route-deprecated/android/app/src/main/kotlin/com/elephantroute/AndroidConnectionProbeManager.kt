package com.elephantroute

import okhttp3.Call
import okhttp3.Connection
import okhttp3.ConnectionPool
import okhttp3.EventListener
import okhttp3.OkHttpClient
import okhttp3.Request
import java.net.InetSocketAddress
import java.net.Proxy
import java.net.URI
import java.util.Collections
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

data class AndroidConnectionProbeResult(
    val latencyMs: Int,
    val elapsedMs: Int,
    val attempts: List<Int>,
    val connectionCount: Int,
) {
    fun toMap(): Map<String, Any> = mapOf(
        "latencyMs" to latencyMs,
        "elapsedMs" to elapsedMs,
        "attempts" to attempts,
        "connectionCount" to connectionCount,
    )
}

class AndroidConnectionProbeManager {
    private val activeCalls = ConcurrentHashMap<String, MutableSet<Call>>()
    private val cancelledSessions = ConcurrentHashMap.newKeySet<String>()

    fun probe(
        sessionId: String,
        proxyPort: Int,
        testUrl: String,
        timeoutMs: Int,
    ): AndroidConnectionProbeResult {
        require(sessionId.isNotBlank()) { "sessionId is required" }
        require(proxyPort in 1..65535) { "proxyPort is invalid" }
        require(timeoutMs > 0) { "timeoutMs must be positive" }
        val uri = URI(testUrl)
        require(uri.scheme == "http" || uri.scheme == "https") {
            "testUrl must use HTTP or HTTPS"
        }
        require(!uri.host.isNullOrBlank()) { "testUrl host is required" }

        val acquiredConnectionIds = Collections.synchronizedList(mutableListOf<Int>())
        val connectionPool = ConnectionPool(1, 5, TimeUnit.SECONDS)
        val client = OkHttpClient.Builder()
            .proxy(Proxy(Proxy.Type.HTTP, InetSocketAddress("127.0.0.1", proxyPort)))
            .connectionPool(connectionPool)
            .retryOnConnectionFailure(false)
            .followRedirects(false)
            .followSslRedirects(false)
            .connectTimeout(timeoutMs.toLong(), TimeUnit.MILLISECONDS)
            .readTimeout(timeoutMs.toLong(), TimeUnit.MILLISECONDS)
            .writeTimeout(timeoutMs.toLong(), TimeUnit.MILLISECONDS)
            .callTimeout(timeoutMs.toLong(), TimeUnit.MILLISECONDS)
            .eventListenerFactory {
                object : EventListener() {
                    override fun connectionAcquired(call: Call, connection: Connection) {
                        acquiredConnectionIds += System.identityHashCode(connection)
                    }
                }
            }
            .build()
        val request = Request.Builder()
            .url(testUrl)
            .get()
            .header("Connection", "keep-alive")
            .build()
        val startedAt = System.nanoTime()
        val deadline = startedAt + TimeUnit.MILLISECONDS.toNanos(timeoutMs.toLong())
        val attempts = mutableListOf<Int>()

        try {
            repeat(2) {
                if (cancelledSessions.contains(sessionId)) {
                    attempts += -1
                    return@repeat
                }
                val remainingNanos = deadline - System.nanoTime()
                if (remainingNanos <= 0) {
                    attempts += -1
                    return@repeat
                }
                val call = client.newCall(request)
                call.timeout().timeout(remainingNanos, TimeUnit.NANOSECONDS)
                register(sessionId, call)
                val attemptStartedAt = System.nanoTime()
                try {
                    call.execute().use { response ->
                        response.body?.close()
                        attempts += if (response.code == 200 || response.code == 204) {
                            TimeUnit.NANOSECONDS.toMillis(
                                System.nanoTime() - attemptStartedAt,
                            ).toInt()
                        } else {
                            -1
                        }
                    }
                } catch (_: Exception) {
                    attempts += -1
                } finally {
                    unregister(sessionId, call)
                }
            }
        } finally {
            connectionPool.evictAll()
            client.dispatcher.executorService.shutdown()
        }

        while (attempts.size < 2) attempts += -1
        val validAttempts = attempts.filter { it >= 0 }
        return AndroidConnectionProbeResult(
            latencyMs = validAttempts.minOrNull() ?: -1,
            elapsedMs = TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - startedAt).toInt(),
            attempts = attempts.toList(),
            connectionCount = acquiredConnectionIds.toSet().size,
        )
    }

    fun cancel(sessionId: String) {
        cancelledSessions += sessionId
        activeCalls.remove(sessionId)?.toList()?.forEach(Call::cancel)
    }

    fun cancelAll() {
        activeCalls.keys.toList().forEach(::cancel)
    }

    private fun register(sessionId: String, call: Call) {
        val calls = activeCalls.computeIfAbsent(sessionId) {
            ConcurrentHashMap.newKeySet()
        }
        calls += call
        if (cancelledSessions.contains(sessionId)) call.cancel()
    }

    private fun unregister(sessionId: String, call: Call) {
        activeCalls[sessionId]?.let { calls ->
            calls -= call
            if (calls.isEmpty()) activeCalls.remove(sessionId, calls)
        }
    }
}
