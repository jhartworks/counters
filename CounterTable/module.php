<?
// Klassendefinition
class CounterTable extends IPSModule {
    // Überschreibt die interne IPS_Create($id) Funktion
    public function Create() {
        // Diese Zeile nicht löschen.
        parent::Create();


        $this->RegisterPropertyFloat("Price",0.23);
        $this->RegisterPropertyString("Counters","");

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
        $price = $this->ReadPropertyFloat("Price");


        $arrString = $this->ReadPropertyString("Counters");
        $arr = json_decode($arrString);
        

        $dataoutput = "";
       /// print_r($arr);

        if($arr != NULL){
            foreach($arr as $cnt){

                $cost = 0.0;
                $cost = $cnt->Monthly * $price;
                $style ='';
                if ($cnt->Monthly > $cnt->Limit){
                    $style = 'style="background-color:yellow;"';
                }
                
                $dataoutput .= '        
                <tr '.$style.'>
                
                    <td style="text-align:left;">
                            '.$cnt->Name.'
                    </td>
                    
                    <td>
                            '.$cnt->Total.' kWh
                    </td>
                    
                    <td>
                            '.$cnt->Monthly.' kWh
                    </td>
                            
                    <td>
                            '.$cnt->Weekly.' kWh
                    </td>
                    
                    <td>
                            '.$cnt->Daily.' kWh
                    </td>
                    
                    <td>
                            '.$cost.'€
                    </td>

                    </tr>';

            }
        }
        $this->createTable($dataoutput);

/* 

                        Array
                (
                    [0] => stdClass Object
                        (
                            [Name] => Blub
                            [Total] => 49294
                            [Monthly] => 49294
                            [Weekly] => 49294
                            [Daily] => 49294
                            [Limit] => 0.1
                        )

                )
                (Code: -32603)
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
                            Name:
                    </th>
                    
                    <th>
                            Gesamt:
                    </th>
                    
                    <th>
                            Monat: 
                    </th>
                    
                    <th>
                            Woche:
                    </th>
                    
                    <th>
                            Tag: 
                    </th>
                    
                    <th>
                            Kosten:
                    </th>
                    

                        
            
            </tr>
            
            </thead>
            ';

       $htmlsuffix = '
       
        
        <tr>
        </tr>		
        </table>
            
        ';

        SetValueString($this->GetIDForIdent("htmlOutput"),$htmlprefix.$data.$htmlsuffix);
    }



}

?>