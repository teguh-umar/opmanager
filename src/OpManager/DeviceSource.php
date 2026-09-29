<?php

namespace GlpiPlugin\Opmanager\OpManager;

interface DeviceSource
{
    /**
     * @return list<array{device_key: string, name: string, display_name: string, ip_address: string,
     *                    device_type: string, device_category: string, availability: string}>
     * @throws ClientException
     */
    public function listDevices(): array;
}
