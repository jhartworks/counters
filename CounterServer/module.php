<?php

class CounterClient extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('CounterCategoryID', 0);
        $this->RegisterPropertyInteger('JsonOutputVariableID', 0);
        $this->RegisterPropertyInteger('UpdateTime', 60);
        $this->RegisterPropertyBoolean('EnableMQTT', false);
        $this->RegisterPropertyInteger('MqttClientID', 0);
        $this->RegisterPropertyString('Projectname', '');
        $this->RegisterPropertyInteger('Projectyear', 2026);
        $this->RegisterPropertyInteger('Projectnumber', 0);

        $this->RegisterTimer('Update', 0, 'SECC_BuildAndStorePayload(' . $this->InstanceID . ');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $updateTime = $this->ReadPropertyInteger('UpdateTime');
        if ($updateTime < 1) {
            $updateTime = 60;
        }

        $this->SetTimerInterval('Update', $updateTime * 1000);
    }

    public function BuildAndStorePayload()
    {
        $json = $this->BuildPayload();
        if ($json === '') {
            return;
        }

        $targetVarId = $this->ReadPropertyInteger('JsonOutputVariableID');
        if ($targetVarId > 0 && IPS_VariableExists($targetVarId)) {
            SetValueString($targetVarId, $json);
        }

        IPS_LogMessage('CounterClient', $json);

        if ($this->ReadPropertyBoolean('EnableMQTT')) {
            $mqttClientId = $this->ReadPropertyInteger('MqttClientID');
            $mqttTopic = 'Projekte' . $this->ReadPropertyInteger('Projectyear') . '/P' . $this->ReadPropertyInteger('Projectnumber') . '/Counters';

            if ($mqttClientId > 0 && IPS_InstanceExists($mqttClientId) && $mqttTopic !== '') {
                $this->MqttPublish($mqttClientId, $mqttTopic, $json, true);
            }
        }
    }

    public function BuildPayload()
    {
        $counterCategoryId = $this->ReadPropertyInteger('CounterCategoryID');
        if ($counterCategoryId <= 0 || !IPS_ObjectExists($counterCategoryId)) {
            IPS_LogMessage('CounterClient', 'Ungültige CounterCategoryID');
            return '';
        }

        $payload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'counters'  => []
        ];

        $counterFolders = IPS_GetChildrenIDs($counterCategoryId);

        foreach ($counterFolders as $counterFolderId) {
            if (!IPS_ObjectExists($counterFolderId)) {
                continue;
            }

            $obj = IPS_GetObject($counterFolderId);
            if ((int)$obj['ObjectType'] !== 0) {
                continue;
            }

            $counterName = IPS_GetName($counterFolderId);
            $counterId = $this->makeSlug($counterName);

            $counterData = [
                'id'   => $counterId,
                'name' => $counterName
            ];

            $found = 0;
            $children = IPS_GetChildrenIDs($counterFolderId);

            foreach ($children as $childId) {
                if (!IPS_ObjectExists($childId)) {
                    continue;
                }

                if (IPS_LinkExists($childId)) {
                    $link = IPS_GetLink($childId);
                    $targetId = (int)$link['TargetID'];
                    $linkName = IPS_GetName($childId);

                    if ($targetId <= 0 || !IPS_ObjectExists($targetId)) {
                        continue;
                    }

                    if (IPS_VariableExists($targetId)) {
                        if ($this->addVariableToCounter($counterData, $targetId, $counterName . ' ' . $linkName)) {
                            $found++;
                        }
                    } else {
                        $subChildren = IPS_GetChildrenIDs($targetId);
                        foreach ($subChildren as $subChildId) {
                            if (IPS_VariableExists($subChildId)) {
                                if ($this->addVariableToCounter($counterData, $subChildId, $counterName . ' ' . $linkName . ' ' . IPS_GetName($targetId))) {
                                    $found++;
                                }
                            }
                        }
                    }

                    continue;
                }

                if (IPS_VariableExists($childId)) {
                    if ($this->addVariableToCounter($counterData, $childId, $counterName)) {
                        $found++;
                    }
                    continue;
                }

                $subChildren = IPS_GetChildrenIDs($childId);
                foreach ($subChildren as $subChildId) {
                    if (IPS_VariableExists($subChildId)) {
                        if ($this->addVariableToCounter($counterData, $subChildId, $counterName . ' ' . IPS_GetName($childId))) {
                            $found++;
                        }
                    }
                }
            }

            $counterData['type'] = $this->DetectCounterType($counterData, $counterName);

            if (!isset($counterData['unit'])) {
                $counterData['unit'] = $this->inferDefaultUnit($counterData);
            }

            if ($found > 0) {
                $payload['counters'][] = $counterData;
            } else {
                IPS_LogMessage('CounterClient', 'Counter ohne erkannte Messwerte übersprungen: ' . $counterName);
            }
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function addVariableToCounter(array &$counterData, int $varId, string $contextPrefix): bool
    {
        if (!IPS_VariableExists($varId)) {
            return false;
        }

        $var = IPS_GetVariable($varId);
        $type = (int)$var['VariableType'];

        if ($type !== 1 && $type !== 2) {
            return false;
        }

        $value = GetValue($varId);
        if (!is_numeric($value)) {
            return false;
        }

        $value = round((float)$value, 3);

        $varName = IPS_GetName($varId);
        $context = mb_strtolower(trim($contextPrefix . ' ' . $varName));
        $unit = $this->getVariableUnit($varId, $var, $value);
        $unitNorm = $this->normalizeUnit($unit);

        if ($unitNorm === '') {
            IPS_LogMessage('CounterClient', 'Keine Einheit erkannt: ' . $context);
            return false;
        }

        if ($this->isTotalUnit($unitNorm, $context)) {
            $counterData['total_value'] = $value;
            if (!isset($counterData['unit'])) {
                $counterData['unit'] = $this->formatOutputUnit($unitNorm);
            }
            return true;
        }

        if ($this->isPowerUnit($unitNorm, $context)) {
            $counterData['power_value'] = $value;
            return true;
        }

        if ($this->isFlowUnit($unitNorm, $context)) {
            $counterData['flow_value'] = $value;
            return true;
        }

        if ($unitNorm === 'a') {
            $counterData['current_value'] = $value;
            return true;
        }

        if ($unitNorm === 'v') {
            $counterData['voltage_value'] = $value;
            return true;
        }

        if ($unitNorm === 'hz') {
            $counterData['frequency_value'] = $value;
            return true;
        }

        if (in_array($unitNorm, ['bar', 'mbar', 'pa'], true)) {
            if ($this->looksLikeOut($context)) {
                $counterData['pressure_out_value'] = $value;
            } else {
                $counterData['pressure_in_value'] = $value;
            }
            return true;
        }

        if (in_array($unitNorm, ['°c', 'c', 'k'], true)) {
            if ($this->looksLikeOut($context)) {
                $counterData['temperature_out_value'] = $value;
            } else {
                $counterData['temperature_in_value'] = $value;
            }
            return true;
        }

        if ($unitNorm === 'cos' || $unitNorm === 'cosphi' || $unitNorm === 'pf') {
            $counterData['power_factor'] = $value;
            return true;
        }

        IPS_LogMessage('CounterClient', 'Nicht zugeordnet: ' . $context . ' | Einheit: ' . $unitNorm . ' | Wert: ' . $value);
        return false;
    }

    private function getVariableUnit(int $varId, array $var, float $value): string
    {
        if (function_exists('IPS_GetPresentation')) {
            try {
                $presentation = @IPS_GetPresentation($varId);
                if (is_array($presentation)) {
                    if (isset($presentation['Suffix']) && trim((string)$presentation['Suffix']) !== '') {
                        return trim((string)$presentation['Suffix']);
                    }
                    if (isset($presentation['Prefix']) && trim((string)$presentation['Prefix']) !== '') {
                        return trim((string)$presentation['Prefix']);
                    }
                    if (isset($presentation['Unit']) && trim((string)$presentation['Unit']) !== '') {
                        return trim((string)$presentation['Unit']);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $profileName = '';
        if (isset($var['VariableCustomProfile']) && $var['VariableCustomProfile'] !== '') {
            $profileName = $var['VariableCustomProfile'];
        } elseif (isset($var['VariableProfile']) && $var['VariableProfile'] !== '') {
            $profileName = $var['VariableProfile'];
        }

        if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
            $profile = IPS_GetVariableProfile($profileName);
            $suffix = trim((string)$profile['Suffix']);
            $prefix = trim((string)$profile['Prefix']);
            $unit = trim($prefix . ' ' . $suffix);
            if ($unit !== '') {
                return $unit;
            }
        }

        $formatted = GetValueFormatted($varId);
        return $this->extractUnitFromFormattedValue($formatted, $value);
    }

    private function extractUnitFromFormattedValue(string $formatted, float $value): string
    {
        $formatted = trim($formatted);
        if ($formatted === '') {
            return '';
        }

        $search = [
            number_format($value, 0, ',', '.'),
            number_format($value, 1, ',', '.'),
            number_format($value, 2, ',', '.'),
            number_format($value, 3, ',', '.'),
            str_replace('.', ',', (string)$value),
            str_replace(',', '.', (string)$value),
            (string)$value
        ];

        $unit = $formatted;
        foreach ($search as $needle) {
            if ($needle !== '') {
                $unit = str_replace($needle, '', $unit);
            }
        }

        $unit = trim($unit);
        $unit = preg_replace('/^[\-\+\d\.,\s]+/u', '', $unit);
        return trim($unit);
    }

    private function DetectCounterType(array $counterData, string $context): string
    {
        $unit = isset($counterData['unit']) ? $this->normalizeUnit((string)$counterData['unit']) : '';
        $ctx = mb_strtolower($context);

        if (isset($counterData['voltage_value']) || isset($counterData['current_value']) || isset($counterData['frequency_value']) || isset($counterData['power_factor'])) {
            return 'electricity';
        }

        if (isset($counterData['temperature_in_value']) || isset($counterData['temperature_out_value'])) {
            if (strpos($ctx, 'kalt') !== false || strpos($ctx, 'cool') !== false || strpos($ctx, 'kaelte') !== false || strpos($ctx, 'kälte') !== false) {
                return 'cooling';
            }
            return 'heat';
        }

        if ($unit === 'm3' || $unit === 'l') {
            if (strpos($ctx, 'gas') !== false) {
                return 'gas';
            }
            return 'water';
        }

        if ($unit === 'kwh' || $unit === 'kw/h' || $unit === 'mwh' || $unit === 'wh') {
            if (isset($counterData['flow_value']) || strpos($ctx, 'waerme') !== false || strpos($ctx, 'wärme') !== false || strpos($ctx, 'warmwasser') !== false || strpos($ctx, 'heizung') !== false) {
                return 'heat';
            }
            return 'electricity';
        }

        if (strpos($ctx, 'gas') !== false) {
            return 'gas';
        }

        return 'other';
    }

    private function inferDefaultUnit(array $counterData): string
    {
        if (isset($counterData['total_value'])) {
            if ($counterData['type'] === 'water' || $counterData['type'] === 'gas') {
                return 'm³';
            }
            return 'kWh';
        }

        return '';
    }

    private function isTotalUnit(string $unitNorm, string $context): bool
    {
        if (in_array($unitNorm, ['kwh', 'wh', 'mwh', 'm3', 'l', 'kw/h', 'w/h', 'mw/h'], true)) {
            return true;
        }

        if (strpos($context, 'energy') !== false && in_array($unitNorm, ['kw', 'w', 'mw'], true)) {
            return true;
        }

        if (strpos($context, 'volume') !== false && in_array($unitNorm, ['m3', 'l'], true)) {
            return true;
        }

        return false;
    }

    private function isPowerUnit(string $unitNorm, string $context): bool
    {
        if (in_array($unitNorm, ['kw', 'w', 'mw'], true)) {
            if (strpos($context, 'energy') !== false) {
                return false;
            }
            return true;
        }

        return false;
    }

    private function isFlowUnit(string $unitNorm, string $context): bool
    {
        if (in_array($unitNorm, ['m3/h', 'l/h', 'l/min'], true)) {
            return true;
        }

        if (strpos($context, 'flow') !== false && in_array($unitNorm, ['m3', 'l'], true)) {
            return true;
        }

        return false;
    }

    private function formatOutputUnit(string $unitNorm): string
    {
        if ($unitNorm === 'm3') {
            return 'm³';
        }
        if ($unitNorm === 'kwh') {
            return 'kWh';
        }
        if ($unitNorm === 'kw/h') {
            return 'kW/h';
        }
        return $unitNorm;
    }

    private function looksLikeIn(string $name): bool
    {
        $name = mb_strtolower($name);

        return strpos($name, 'vorlauf') !== false
            || strpos($name, 'vl') !== false
            || strpos($name, 'flow') !== false
            || strpos($name, 'ein') !== false
            || strpos($name, 'iv') !== false
            || strpos($name, 'inlet') !== false;
    }

    private function looksLikeOut(string $name): bool
    {
        $name = mb_strtolower($name);

        return strpos($name, 'ruecklauf') !== false
            || strpos($name, 'rücklauf') !== false
            || strpos($name, 'rl') !== false
            || strpos($name, 'return') !== false
            || strpos($name, 'aus') !== false
            || strpos($name, 'ri') !== false
            || strpos($name, 'outlet') !== false;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace(["\xc2\xa0", ' '], '', $unit);
        $unit = str_replace(['m³', '㎥'], 'm3', $unit);
        return $unit;
    }

    private function makeSlug(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
        $text = preg_replace('/[^a-z0-9]+/u', '_', $text);
        $text = trim($text, '_');

        if ($text === '') {
            $text = 'counter_' . time();
        }

        return $text;
    }

    public function MqttPublish($server_id, $topic, $payload, $retain)
    {
        if (!IPS_InstanceExists($server_id)) {
            return false;
        }

        $ips_var_type = 3;
        $module_id = '{01C00ADD-D04E-452E-B66A-D253278743FE}';
        $ident = 'TempMQTTDevice_' . $this->InstanceID;

        if (!IPS_SemaphoreEnter($ident, 100)) {
            return false;
        }

        try {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id === false) {
                $id = @IPS_CreateInstance($module_id);
                if ($id === false) {
                    return false;
                }
                IPS_SetParent($id, $this->InstanceID);
                IPS_SetIdent($id, $ident);
            }

            if (!IPS_IsInstanceCompatible($id, $server_id)) {
                return false;
            }

            $inst_config = IPS_GetInstance($id);
            if ((int)$inst_config['ConnectionID'] !== (int)$server_id) {
                IPS_DisconnectInstance($id);
                if (!@IPS_ConnectInstance($id, $server_id)) {
                    return false;
                }
            }

            IPS_SetName($id, 'Temporary MQTT Device for: ' . $topic);

            $config_arr = [
                'Retain' => $retain,
                'Topic'  => $topic,
                'Type'   => $ips_var_type
            ];

            IPS_SetConfiguration($id, json_encode($config_arr));
            IPS_SetHidden($id, true);
            IPS_ApplyChanges($id);

            $var_id = @IPS_GetObjectIDByIdent('Value', $id);
            if ($var_id === false) {
                return false;
            }

            RequestAction($var_id, $payload);
        } finally {
            IPS_SemaphoreLeave($ident);
        }

        return true;
    }
}
?>