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
import java.net.SocketTimeoutException
import java.io.InterruptedIOException
import java.io.IOException
import java.security.MessageDigest
import java.util.Collections
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

data class AndroidConnectionProbeResult(
    val latencyMs: Int,
    val elapsedMs: Int,
    val attempts: List<Int>,
    val connectionCount: Int,
    val failureKind: String?,
    val httpStatusCodes: List<Int>,
) {
    fun toMap(): Map<String, Any> = mapOf(
        "latencyMs" to latencyMs,
        "elapsedMs" to elapsedMs,
        "attempts" to attempts,
        "connectionCount" to connectionCount,
        "failureKind" to (failureKind ?: ""),
        "httpStatusCodes" to httpStatusCodes,
    )
}

class AndroidConnectionProbeManager {
    private enum class FailureKind(val wireValue: String) {
        TIMEOUT("timeout"),
        HTTP_ERROR("httpError"),
        TRANSPORT_ERROR("transportError"),
        CANCELLED("cancelled"),
    }

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
        val httpStatusCodes = mutableListOf<Int>()
        val failures = mutableListOf<FailureKind>()

        try {
            repeat(2) {
                if (cancelledSessions.contains(sessionId)) {
                    attempts += -1
                    httpStatusCodes += 0
                    failures += FailureKind.CANCELLED
                    return@repeat
                }
                val remainingNanos = deadline - System.nanoTime()
                if (remainingNanos <= 0) {
                    attempts += -1
                    httpStatusCodes += 0
                    failures += FailureKind.TIMEOUT
                    return@repeat
                }
                val call = client.newCall(request)
                call.timeout().timeout(remainingNanos, TimeUnit.NANOSECONDS)
                register(sessionId, call)
                val attemptStartedAt = System.nanoTime()
                try {
                    call.execute().use { response ->
                        response.body?.close()
                        httpStatusCodes += response.code
                        attempts += if (response.code == 200 || response.code == 204) {
                            TimeUnit.NANOSECONDS.toMillis(
                                System.nanoTime() - attemptStartedAt,
                            ).toInt().coerceAtLeast(1)
                        } else {
                            failures += FailureKind.HTTP_ERROR
                            -1
                        }
                    }
                } catch (_: SocketTimeoutException) {
                    attempts += -1
                    httpStatusCodes += 0
                    failures += FailureKind.TIMEOUT
                } catch (_: InterruptedIOException) {
                    attempts += -1
                    httpStatusCodes += 0
                    failures += if (cancelledSessions.contains(sessionId)) {
                        FailureKind.CANCELLED
                    } else {
                        FailureKind.TIMEOUT
                    }
                } catch (_: IOException) {
                    attempts += -1
                    httpStatusCodes += 0
                    failures += if (cancelledSessions.contains(sessionId)) {
                        FailureKind.CANCELLED
                    } else {
                        FailureKind.TRANSPORT_ERROR
                    }
                } finally {
                    unregister(sessionId, call)
                }
            }
        } finally {
            connectionPool.evictAll()
            client.dispatcher.executorService.shutdown()
        }

        while (attempts.size < 2) attempts += -1
        while (httpStatusCodes.size < 2) httpStatusCodes += 0
        val validAttempts = attempts.filter { it >= 0 }
        val failureKind = if (validAttempts.isNotEmpty()) {
            null
        } else {
            when {
                failures.contains(FailureKind.CANCELLED) -> FailureKind.CANCELLED
                failures.contains(FailureKind.TIMEOUT) -> FailureKind.TIMEOUT
                failures.contains(FailureKind.HTTP_ERROR) -> FailureKind.HTTP_ERROR
                else -> FailureKind.TRANSPORT_ERROR
            }.wireValue
        }
        return AndroidConnectionProbeResult(
            latencyMs = validAttempts.minOrNull() ?: -1,
            elapsedMs = TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - startedAt).toInt(),
            attempts = attempts.toList(),
            connectionCount = acquiredConnectionIds.toSet().size,
            failureKind = failureKind,
            httpStatusCodes = httpStatusCodes.toList(),
        )
    }

    fun cancel(sessionId: String) {
        cancelledSessions += sessionId
        activeCalls.remove(sessionId)?.toList()?.forEach(Call::cancel)
    }

    fun cancelAll() {
        activeCalls.keys.toList().forEach(::cancel)
    }

    companion object {
        fun nodeKey(nodeTag: String): String {
            val digest = MessageDigest.getInstance("SHA-256")
                .digest(nodeTag.toByteArray(Charsets.UTF_8))
            return digest.take(6).joinToString("") { byte -> "%02x".format(byte) }
        }
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
