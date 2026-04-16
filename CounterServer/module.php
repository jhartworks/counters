<?php

class CounterServer extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Connector', 0);
        $this->RegisterPropertyInteger('SourceJSON', 0);
        $this->RegisterPropertyInteger('UpdateTime', 60);
        $this->RegisterPropertyString('Projectname', 60);
        $this->RegisterPropertyInteger('ProjectIdPortal', -1);
        $this->RegisterTimer('Update', 0, 'SECS_checkTable('.$this->InstanceID.');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $updateTime = $this->ReadPropertyInteger('UpdateTime');
        if ($updateTime < 1) {
            $updateTime = 60;
        }

        $this->SetTimerInterval('Update', $updateTime * 1000);
        $this->checkTable();
    }

    public function checkTable()
    {
        $sqlId = $this->ReadPropertyInteger('Connector');
        $jsonVar = $this->ReadPropertyInteger('SourceJSON');

        if ($sqlId <= 0 || $jsonVar <= 0) {
            IPS_LogMessage('CounterTable', 'Config fehlt.');
            return;
        }

        $jsonString = GetValueString($jsonVar);
        if ($jsonString === '') {
            return;
        }

        $payload = json_decode($jsonString, true);
        if (!is_array($payload)) {
            IPS_LogMessage('CounterTable', 'JSON Fehler');
            return;
        }

        $clientId = (string)$this->InstanceID;
        $clientName = IPS_GetName($this->InstanceID);
        $projectName = $this->ReadPropertyString('Projectname');
        $timestamp = $payload['timestamp'] ?? date('Y-m-d H:i:s');
        $counters = $payload['counters'] ?? [];
        $projectId = $this->ReadPropertyInteger('ProjectIdPortal');

        if ($projectId !== -1) {
        foreach ($counters as $c) {

            $counterId = $c['id'] ?? '';
            if ($counterId === '') continue;

            $counterName = $c['name'] ?? $counterId;
            $type = $c['type'] ?? 'other';
            $unit = $c['unit'] ?? 'kWh';


            // ---------- UPSERT ----------
            $sql = "
                INSERT INTO meter_devices
                (project_id, counter_id, meter_uuid, external_id, external_name, meter_type, billing_unit, is_active)
                VALUES
                (
                    '" .  $this->esc($projectId) . "',
                    '" . $this->esc($counterId) . "',
                    '" . hash('sha256', $projectId.$clientId . '_' . $counterId) . "',
                    '" . $this->esc($clientId) . "',
                    '" . $this->esc($projectName.'_'. $counterId) . "',
                    '" . $this->esc($type) . "',
                    '" . $this->esc($unit) . "',
                    1
                )
                ON DUPLICATE KEY UPDATE
                    meter_name = VALUES(meter_name),
                    meter_type = VALUES(meter_type),
                    billing_unit = VALUES(billing_unit),
                    is_active = 1
            ";

                
            MySQL_ExecuteSimple($sqlId, $sql);
            

            // ---------- GET meter_id ----------
            $sqlGet = "
                SELECT id FROM meter_devices
                WHERE meter_uuid = '" . hash('sha256', $projectId.$clientId . '_' . $counterId) . "'
                LIMIT 1
            ";

            $res = MySQL_ExecuteSimple($sqlId, $sqlGet);

            if (!is_array($res) || count($res) === 0) {
                IPS_LogMessage('CounterTable', 'Kein meter_id gefunden');
                continue;
            }

            $meterId = $res[0]->id;

            // ---------- INSERT measurement ----------
            $sqlInsert = "
                INSERT INTO meter_measurements
                (
                    meter_id, measured_at,
                    total_value, consumption_value,
                    power_value, flow_value,
                    current_value, voltage_value,
                    temperature_in_value, temperature_out_value,
                    pressure_in_value, pressure_out_value,
                    frequency_value, power_factor
                )
                VALUES
                (
                    " . $meterId . ",
                    '" . $this->esc($timestamp) . "',
                    " . $this->f($c, 'total_value') . ",
                    " . $this->f($c, 'consumption_value') . ",
                    " . $this->f($c, 'power_value') . ",
                    " . $this->f($c, 'flow_value') . ",
                    " . $this->f($c, 'current_value') . ",
                    " . $this->f($c, 'voltage_value') . ",
                    " . $this->f($c, 'temperature_in_value') . ",
                    " . $this->f($c, 'temperature_out_value') . ",
                    " . $this->f($c, 'pressure_in_value') . ",
                    " . $this->f($c, 'pressure_out_value') . ",
                    " . $this->f($c, 'frequency_value') . ",
                    " . $this->f($c, 'power_factor') . "
                )
            ";

            MySQL_ExecuteSimple($sqlId, $sqlInsert);
        }
        } else {
            IPS_LogMessage('CounterTable', 'Keine Project-ID für Portal angegeben.');
        }
    }

    private function f($arr, $key)
    {
        if (!isset($arr[$key]) || $arr[$key] === '' || $arr[$key] === null) {
            return 'NULL';
        }

        if (is_numeric($arr[$key])) {
            return str_replace(',', '.', (string)$arr[$key]);
        }

        return 'NULL';
    }

    private function esc($v)
    {
        return str_replace(
            ['\\', "'", '"'],
            ['\\\\', "\\'", '\\"'],
            $v
        );
    }
}
?>