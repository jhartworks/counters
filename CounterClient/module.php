<?php

class CounterClient extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('CounterCategoryID', 0);
        $this->RegisterPropertyInteger('JsonOutputVariableID', 0);
        $this->RegisterPropertyInteger('UpdateTime', 60);

        $this->RegisterTimer('Update', 0, 'SECC_BuildAndStorePayload($_IPS[\'TARGET\']);');
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
            $obj = IPS_GetObject($counterObjectId);

            if ($obj['ObjectType'] != 0) {
                continue;
            }

            $counterName = IPS_GetName($counterObjectId);
            $counterId = $this->makeSlug($counterName);

            $varIds = $this->CollectVariablesRecursive($counterObjectId);
            if (count($varIds) === 0) {
                continue;
            }

            $counterData = [
                'id'   => $counterId,
                'name' => $counterName
            ];

            $tempSlots = [];
            $pressureSlots = [];

            foreach ($varIds as $varId) {
                $classified = $this->ClassifyVariable($varId);
                if ($classified === null) {
                    continue;
                }

                $field = $classified['field'];
                $value = $classified['value'];
                $unit  = $classified['unit'];

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

            $counterData['type'] = $this->DetectCounterType($counterData);

            if (!isset($counterData['unit'])) {
                $counterData['unit'] = $this->inferDefaultUnit($counterData);
            }

            if (count($counterData) > 3) {
                $payload['counters'][] = $counterData;
            }
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function CollectVariablesRecursive(int $objectId): array
    {
        $result = [];
        $children = IPS_GetChildrenIDs($objectId);

        foreach ($children as $childId) {
            if (IPS_LinkExists($childId)) {
                $link = IPS_GetLink($childId);
                $targetId = (int) $link['TargetID'];

                if (IPS_VariableExists($targetId)) {
                    $result[$targetId] = $targetId;
                } elseif (IPS_ObjectExists($targetId)) {
                    foreach ($this->CollectVariablesRecursive($targetId) as $subVarId) {
                        $result[$subVarId] = $subVarId;
                    }
                }

                continue;
            }

            if (IPS_VariableExists($childId)) {
                $result[$childId] = $childId;
                continue;
            }

            if (IPS_ObjectExists($childId)) {
                foreach ($this->CollectVariablesRecursive($childId) as $subVarId) {
                    $result[$subVarId] = $subVarId;
                }
            }
        }

        return array_values($result);
    }

    private function ClassifyVariable(int $varId): ?array
    {
        if (!IPS_VariableExists($varId)) {
            return null;
        }

        $var = IPS_GetVariable($varId);
        $type = (int) $var['VariableType'];

        if ($type !== 1 && $type !== 2) {
            return null;
        }

        $profileName = '';
        if ($var['VariableCustomProfile'] !== '') {
            $profileName = $var['VariableCustomProfile'];
        } elseif ($var['VariableProfile'] !== '') {
            $profileName = $var['VariableProfile'];
        }

        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return null;
        }

        $profile = IPS_GetVariableProfile($profileName);
        $suffix = trim((string) $profile['Suffix']);
        $prefix = trim((string) $profile['Prefix']);
        $unit = trim($prefix . ' ' . $suffix);

        if ($unit === '') {
            return null;
        }

        $value = GetValue($varId);
        if (!is_numeric($value)) {
            return null;
        }

        $value = round((float) $value, 3);
        $varName = mb_strtolower(IPS_GetName($varId));
        $unitNorm = $this->normalizeUnit($unit);

        if (in_array($unitNorm, ['kwh', 'wh', 'mwh', 'm3', 'l'], true)) {
            return [
                'field' => 'total_value',
                'value' => $value,
                'unit'  => $suffix !== '' ? trim($suffix) : $unitNorm,
                'name'  => $varName
            ];
        }

        if (in_array($unitNorm, ['kw', 'w', 'mw'], true)) {
            return [
                'field' => 'power_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if (in_array($unitNorm, ['m3/h', 'l/h', 'l/min'], true)) {
            return [
                'field' => 'flow_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if ($unitNorm === 'a') {
            return [
                'field' => 'current_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if ($unitNorm === 'v') {
            return [
                'field' => 'voltage_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if ($unitNorm === 'hz') {
            return [
                'field' => 'frequency_value',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if (in_array($unitNorm, ['bar', 'mbar', 'pa'], true)) {
            if ($this->looksLikeIn($varName)) {
                return [
                    'field' => 'pressure_in_value',
                    'value' => $value,
                    'unit'  => $unitNorm,
                    'name'  => $varName
                ];
            }

            if ($this->looksLikeOut($varName)) {
                return [
                    'field' => 'pressure_out_value',
                    'value' => $value,
                    'unit'  => $unitNorm,
                    'name'  => $varName
                ];
            }

            return [
                'field' => 'pressure_auto',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if (in_array($unitNorm, ['°c', 'c', 'k'], true)) {
            if ($this->looksLikeIn($varName)) {
                return [
                    'field' => 'temperature_in_value',
                    'value' => $value,
                    'unit'  => $unitNorm,
                    'name'  => $varName
                ];
            }

            if ($this->looksLikeOut($varName)) {
                return [
                    'field' => 'temperature_out_value',
                    'value' => $value,
                    'unit'  => $unitNorm,
                    'name'  => $varName
                ];
            }

            return [
                'field' => 'temperature_auto',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

        if ($unitNorm === 'cos' || $unitNorm === 'cosphi' || $unitNorm === 'pf') {
            return [
                'field' => 'power_factor',
                'value' => $value,
                'unit'  => $unitNorm,
                'name'  => $varName
            ];
        }

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

    private function DetectCounterType(array $counterData): string
    {
        $unit = isset($counterData['unit']) ? $this->normalizeUnit((string) $counterData['unit']) : '';

        if (isset($counterData['voltage_value']) || isset($counterData['current_value']) || isset($counterData['frequency_value']) || isset($counterData['power_factor'])) {
            return 'electricity';
        }

        if (isset($counterData['temperature_in_value']) || isset($counterData['temperature_out_value'])) {
            return 'heat';
        }

        if ($unit === 'm3' || $unit === 'l') {
            return 'water';
        }

        if ($unit === 'kwh' || $unit === 'mwh' || $unit === 'wh') {
            if (isset($counterData['temperature_in_value']) || isset($counterData['flow_value'])) {
                return 'heat';
            }

            return 'electricity';
        }

        return 'other';
    }

    private function inferDefaultUnit(array $counterData): string
    {
        if (isset($counterData['total_value'])) {
            if ($counterData['type'] === 'water') {
                return 'm3';
            }

            return 'kWh';
        }

        return '';
    }

    private function looksLikeIn(string $name): bool
    {
        return strpos($name, 'vorlauf') !== false
            || strpos($name, 'vl') !== false
            || strpos($name, 'flow') !== false
            || strpos($name, 'ein') !== false
            || strpos($name, 'in') !== false;
    }

    private function looksLikeOut(string $name): bool
    {
        return strpos($name, 'ruecklauf') !== false
            || strpos($name, 'rücklauf') !== false
            || strpos($name, 'rl') !== false
            || strpos($name, 'return') !== false
            || strpos($name, 'aus') !== false
            || strpos($name, 'out') !== false;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit));
        $unit = str_replace([' ', '°'], ['', '°'], $unit);
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
}
?>