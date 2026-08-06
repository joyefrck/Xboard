package com.elephantroute

import io.nekohasekai.libbox.Libbox

internal class VpnRuntimePolicy(
    private val notificationIntervalMs: Long = 10_000L,
) {
    private var lastNotificationAtMs: Long? = null

    @Synchronized
    fun shouldUpdateNotification(nowMs: Long): Boolean {
        val lastUpdate = lastNotificationAtMs
        if (lastUpdate != null && nowMs >= lastUpdate && nowMs - lastUpdate < notificationIntervalMs) {
            return false
        }
        lastNotificationAtMs = nowMs
        return true
    }

    fun commands(isDebug: Boolean): List<Int> = if (isDebug) {
        listOf(Libbox.CommandStatus, Libbox.CommandGroup)
    } else {
        listOf(Libbox.CommandStatus)
    }

    fun singBoxLogLevel(isDebug: Boolean): String = if (isDebug) "debug" else "warn"
}
