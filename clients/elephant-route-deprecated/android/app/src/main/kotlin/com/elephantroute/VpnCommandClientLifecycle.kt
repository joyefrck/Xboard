package com.elephantroute

internal class VpnCommandClientLifecycle {
    private var generation = 0L
    private var destroyed = false

    @Synchronized
    fun beginConnect(): Long {
        generation++
        return generation
    }

    @Synchronized
    fun invalidate() {
        generation++
    }

    @Synchronized
    fun destroy() {
        destroyed = true
        generation++
    }

    @Synchronized
    fun isCurrent(token: Long, isConnected: Boolean): Boolean =
        !destroyed && isConnected && token == generation

    @Synchronized
    fun publishIfCurrent(
        token: Long,
        isConnected: Boolean,
        publish: () -> Unit,
    ): Boolean {
        if (!isCurrent(token, isConnected)) return false
        publish()
        return true
    }
}
