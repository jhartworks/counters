<?
// Klassendefinition
class CounterTable extends IPSModule {
    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();


        $this->RegisterPropertyFloat("Price",0.23);
        $this->ReadPropertyString("Counters","");

        $this->RegisterVariableString("htmlOutput", "Zählerübersicht",["PRESENTATION"=> VARIABLE_PRESENTATION_LEGACY, "PROFILE" => "~HTMLBox"],0);

    }

    // Überschreibt die intere IPS_ApplyChanges($id) Funktion
    public function ApplyChanges() {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();
        $this->checkTable();


    }
    public function RequestAction($Ident, $Value) {
                $varid = $this->GetIDForIdent($Ident);
                SetValue($varid, $Value);
    }


    public function checkTable(){
        $Idvalue = $this->ReadPropertyFloat("Price");


        $arrString = $this->ReadPropertyString("Counters");
        $arr = json_decode($arrString);

        print_r($arr);

/* 
        '<tr style="background-color:'.$ColorGrenzeGesamt.';">
            
        <td style="text-align:left;">
                Gesamt
        </td>
        
        <td>
                '.$ZaehlerGesamtGesamt.'
        </td>
        
        <td>
                '.$ZaehlerGesamtMonat.'
        </td>
                
        <td>
                '.$GrenzeZaehlerGesamt.' kWh
        </td>
        
        <td>
                '.$ZaehlerGesamtTag.'
        </td>
        
        <td>
                '.$KostenGesamt.'€
        </td>

        </tr>'
 */


    }

    public function createTable($data)
    {
        $htmlprefix ='     <html>
            <style>
            table, td {
            
            border-collapse: separate;
            border-spacing: 5px;
            }
            
            td {
            color:#000000;
            font-family:Arial;
            font-size:14pt;
            width:150px;
            text-align:center;
            }
            
            th {
            color:#000000;
            font-family:Arial;
            font-size:14pt;
            width:150px;
            text-align:center;
            }
            
            
            </style>
            
            <body>		
            
                    
            <table style="hight:100%;background-color:lightgrey;border:2px solid green;">
            
            <thead>
            <tr>
                    <th  style="">
                    </th>
                    
                    <th>
                            Gesamt:
                    </th>
                    
                    <th>
                            Monat: 
                    </th>
                    
                    <th>
                            Monatsgerenze:
                    </th>
                    
                    <th>
                            Heute: 
                    </th>
                    
                    <th>
                            Kosten:
                    </th>
                    

                        
            
            </tr>
            
            </thead>
            <tr style="background-color:'.$ColorGrenzeGesamt.';">
            
                    <td style="text-align:left;">
                            Gesamt
                    </td>
                    
                    <td>
                            '.$ZaehlerGesamtGesamt.'
                    </td>
                    
                    <td>
                            '.$ZaehlerGesamtMonat.'
                    </td>
                            
                    <td>
                            '.$GrenzeZaehlerGesamt.' kWh
                    </td>
                    
                    <td>
                            '.$ZaehlerGesamtTag.'
                    </td>
                    
                    <td>
                            '.$KostenGesamt.'€
                    </td>
            
            </tr>';

       $htmlsuffix = '
       
        
        <tr>
        </tr>		
        </table>
            
        ';

        SetValueString($this->GetIDForIdent("htmlOutput"),$htmlprefix.$data.$htmlsuffix);
    }



}

?>