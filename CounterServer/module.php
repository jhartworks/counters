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
            } else {
                IPS_LogMessage('CounterClient', 'MQTT ist aktiviert, aber ungültige MQTT Client ID oder Topic.');
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

        $counterIds = IPS_GetChildrenIDs($counterCategoryId);

        $payload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'counters'  => []
        ];

        foreach ($counterIds as $counterObjectId) {
            if (!IPS_ObjectExists($counterObjectId)) {
                continue;
            }

            $obj = IPS_GetObject($counterObjectId);
            if ((int)$obj['ObjectType'] !== 0) {
                continue;
            }

            $counterName = IPS_GetName($counterObjectId);
            $counterId = $this->makeSlug($counterName);

            $entries = $this->CollectVariablesRecursive($counterObjectId, [], []);
            IPS_LogMessage('CounterClient', 'Counter "' . $counterName . '" -> gefundene Variablen: ' . count($entries));

            if (count($entries) === 0) {
                continue;
            }

            $counterData = [
                'id'   => $counterId,
                'name' => $counterName
            ];

            $tempSlots = [];
            $pressureSlots = [];
            $recognizedCount = 0;

            foreach ($entries as $entry) {
                $classified = $this->ClassifyEntry($entry);
                if ($classified === null) {
                    IPS_LogMessage('CounterClient', 'Ignoriert: ' . $entry['context']);
                    continue;
                }

                $recognizedCount++;

                $field = $classified['field'];
                $value = $classified['value'];
                $unit = $classified['unit'];

                IPS_LogMessage('CounterClient', 'Erkannt: ' . $entry['context'] . ' => ' . $field . ' = ' . $value . ' [' . $unit . ']');

                if ($field === 'temperature_auto') {
                    $tempSlots[] = $classified;
                    continue;
                }

                if ($field === 'pressure_auto') {
                    $pressureSlots[] = $classified;
                    continue;
                }

                $counterData[$field] = $value;

                if ($field === 'total_value' && !isset($counterData['unit']) && $unit !== '') {
                    $counterData['unit'] = $unit;
                }
            }

            $this->assignInOutValues($counterData, $tempSlots, 'temperature_in_value', 'temperature_out_value');
            $this->assignInOutValues($counterData, $pressureSlots, 'pressure_in_value', 'pressure_out_value');

            $contextString = mb_strtolower($counterName);
            foreach ($entries as $entry) {
                $contextString .= ' ' . $entry['context'];
            }

            $counterData['type'] = $this->DetectCounterType($counterData, $contextString);

            if (!isset($counterData['unit'])) {
                $counterData['unit'] = $this->inferDefaultUnit($counterData);
            }

            if ($recognizedCount > 0) {
                $payload['counters'][] = $counterData;
            } else {
                IPS_LogMessage('CounterClient', 'Counter ohne erkannte Messwerte: ' . $counterName);
            }
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function CollectVariablesRecursive(int $objectId, array $path, array $visited): array
    {
        $result = [];

        if (isset($visited[$objectId])) {
            return $result;
        }
        $visited[$objectId] = true;

        if (!IPS_ObjectExists($objectId)) {
            return $result;
        }

        if (IPS_LinkExists($objectId)) {
            $link = IPS_GetLink($objectId);
            $targetId = (int)$link['TargetID'];
            $linkName = IPS_GetName($objectId);

            if ($targetId > 0 && IPS_ObjectExists($targetId)) {
                $newPath = $path;
                if ($linkName !== '') {
                    $newPath[] = $linkName;
                }

                return $this->CollectVariablesRecursive($targetId, $newPath, $visited);
            }

            return $result;
        }

        if (IPS_VariableExists($objectId)) {
            $varName = IPS_GetName($objectId);
            $parts = $path;

            if (count($parts) === 0 || end($parts) !== $varName) {
                $parts[] = $varName;
            }

            $result[] = [
                'varId' => $objectId,
                'context' => implode(' ', $parts)
            ];
            return $result;
        }

        $obj = IPS_GetObject($objectId);
        $objName = IPS_GetName($objectId);

        $newPath = $path;
        if ($objName !== '') {
            $newPath[] = $objName;
        }

        $children = IPS_GetChildrenIDs($objectId);
        foreach ($children as $childId) {
            $sub = $this->CollectVariablesRecursive($childId, $newPath, $visited);
            foreach ($sub as $entry) {
                $result[] = $entry;
            }
        }

        return $result;
    }

private function ClassifyEntry(array $entry): ?array
{
    $varId = (int)$entry['varId'];

    if (!IPS_VariableExists($varId)) {
        return null;
    }

    $var = IPS_GetVariable($varId);
    $type = (int)$var['VariableType'];

    if ($type !== 1 && $type !== 2) {
        return null;
    }

    $value = GetValue($varId);
    if (!is_numeric($value)) {
        return null;
    }

    $value = round((float)$value, 3);
    $context = mb_strtolower($entry['context']);

    $unit = $this->extractUnitFromVariable($varId, $var, $value);
    if ($unit === '') {
        IPS_LogMessage('CounterClient', 'Variable ohne erkennbare Einheit ignoriert: ' . $context);
        return null;
    }

    $unitNorm = $this->normalizeUnit($unit);

    if ($this->isTotalUnit($unitNorm, $context)) {
        return [
            'field' => 'total_value',
            'value' => $value,
            'unit'  => $this->formatOutputUnit($unitNorm),
            'name'  => $context
        ];
    }

    if ($this->isPowerUnit($unitNorm, $context)) {
        return [
            'field' => 'power_value',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if ($this->isFlowUnit($unitNorm, $context)) {
        return [
            'field' => 'flow_value',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if ($unitNorm === 'a') {
        return [
            'field' => 'current_value',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if ($unitNorm === 'v') {
        return [
            'field' => 'voltage_value',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if ($unitNorm === 'hz') {
        return [
            'field' => 'frequency_value',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if (in_array($unitNorm, ['bar', 'mbar', 'pa'], true)) {
        if ($this->looksLikeIn($context)) {
            return [
                'field' => 'pressure_in_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $context
            ];
        }

        if ($this->looksLikeOut($context)) {
            return [
                'field' => 'pressure_out_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $context
            ];
        }

        return [
            'field' => 'pressure_auto',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if (in_array($unitNorm, ['°c', 'c', 'k'], true)) {
        if ($this->looksLikeIn($context)) {
            return [
                'field' => 'temperature_in_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $context
            ];
        }

        if ($this->looksLikeOut($context)) {
            return [
                'field' => 'temperature_out_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $context
            ];
        }

        return [
            'field' => 'temperature_auto',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    if ($unitNorm === 'cos' || $unitNorm === 'cosphi' || $unitNorm === 'pf') {
        return [
            'field' => 'power_factor',
            'value' => $value,
            'unit'  => $unitNorm,
            'name'  => $context
        ];
    }

    IPS_LogMessage('CounterClient', 'Nicht erkannt: ' . $context . ' | Unit: ' . $unitNorm);
    return null;
}
    private function assignInOutValues(array &$counterData, array $items, string $fieldIn, string $fieldOut): void
    {
        foreach ($items as $item) {
            if ($this->looksLikeIn($item['name']) && !isset($counterData[$fieldIn])) {
                $counterData[$fieldIn] = $item['value'];
                continue;
            }

            if ($this->looksLikeOut($item['name']) && !isset($counterData[$fieldOut])) {
                $counterData[$fieldOut] = $item['value'];
                continue;
            }
        }

        foreach ($items as $item) {
            if (!isset($counterData[$fieldIn])) {
                $counterData[$fieldIn] = $item['value'];
                continue;
            }

            if (!isset($counterData[$fieldOut])) {
                $counterData[$fieldOut] = $item['value'];
                continue;
            }
        }
    }
        private function extractUnitFromVariable(int $varId, array $var, float $value): string
    {
        $profileName = '';

        if ($var['VariableCustomProfile'] !== '') {
            $profileName = $var['VariableCustomProfile'];
        } elseif ($var['VariableProfile'] !== '') {
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
        $unitFromFormatted = $this->extractUnitFromFormattedValue($formatted, $value);

        if ($unitFromFormatted !== '') {
            return $unitFromFormatted;
        }

        return '';
    }
        private function extractUnitFromFormattedValue(string $formatted, float $value): string
    {
        $formatted = trim($formatted);

        if ($formatted === '') {
            return '';
        }

        $valueStr1 = number_format($value, 2, ',', '.');
        $valueStr2 = number_format($value, 3, ',', '.');
        $valueStr3 = str_replace('.', ',', (string)$value);
        $valueStr4 = str_replace(',', '.', (string)$value);

        $unit = $formatted;

        $search = [$valueStr1, $valueStr2, $valueStr3, $valueStr4];
        foreach ($search as $needle) {
            if ($needle !== '') {
                $unit = str_replace($needle, '', $unit);
            }
        }

        $unit = trim($unit);

        $unit = preg_replace('/^[\-\+\d\.,\s]+/u', '', $unit);
        $unit = trim($unit);

        return $unit;
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

    private function buildContextString(array $pathParts, string $varName): string
    {
        $parts = $pathParts;
        if ($varName !== '') {
            $parts[] = $varName;
        }

        $parts = array_values(array_filter($parts, function ($v) {
            return trim((string)$v) !== '';
        }));

        return implode(' ', $parts);
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