package com.elephantroute

internal class VpnBroadcastState(initialStatus: String = "disconnected") {
    private var lastStatus = initialStatus

    @Synchronized
    fun resolve(incomingStatus: String?): String {
        if (!incomingStatus.isNullOrBlank()) {
            lastStatus = incomingStatus
        }
        return lastStatus
    }
}
