<?php
    include("../../../include/conexion.php");
    function genera_token()
    {
        $db=conecta_db();
        $endpoint = 'https://altanredes-prod.apigee.net/v1/oauth/accesstoken?grant-type=client_credentials';
        $query="SELECT TOKEN
                FROM SIS_CREDENCIALES_APIS
               WHERE PLATAFORMA = 'ALTAN'";
        $res=OCIParse($db,$query);
        OCIExecute($res,OCI_DEFAULT);
        $arrDatos=array();
        while(OCIFetchInto($res,$arrDatos,OCI_ASSOC+OCI_RETURN_NULLS))
        {
            $token=$arrDatos['TOKEN'];
        }
        OCIFreeStatement($res);
        cerrar_db($db);
        $arrHeaders = array('Authorization: Basic '.$token,'Content-Length: 0');
        $curljson = curl_init($endpoint);
        curl_setopt($curljson, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curljson, CURLOPT_HTTPHEADER, $arrHeaders);
        curl_setopt($curljson, CURLOPT_POST, true);
        $jsonResult = curl_exec($curljson);
        //print_r(json_decode($jsonResult));
        return json_decode($jsonResult);        
    }
    $msisdn=$_REQUEST['msisdn'];
    $request = array();
    //$msisdn='5527614100';
    $db=conecta_db();
    //$db=conecta_desarrollo();
    // CONSULTA DE UN TOQUEN ACTIVO 
    $query="SELECT TOKEN
            FROM   SIS_TOKENS_APIS_EXTERNAS
            WHERE  SYSDATE BETWEEN FECHA_OBTENCION AND FECHA_CADUCIDAD
             AND   PLATAFORMA = 'ALTAN'
             AND   TOKEN IS NOT NULL";
    $res=OCIParse($db,$query);
    //OCIBindByName($res,":P_CLIENTE_ID",$clientId);
    OCIExecute($res,OCI_DEFAULT);
    $arrDatos=array();
    $access_token = '';
    while(OCIFetchInto($res,$arrDatos,OCI_ASSOC+OCI_RETURN_NULLS))
    {
        $access_token=$arrDatos['TOKEN'];
    }
    OCIFreeStatement($res);
    
    //$access_token = '';
    
    //echo $access_token;
    //echo "</br>";
    
    // SI NO HAY TOKEN ACTIVO SE GENERA UNO
    if($access_token=='')
    //if(false)
    {
        $jsonToken = genera_token();
        
        //print_r($jsonToken);
        
        if(isset($jsonToken->accessToken))
        {
            $access_token = $jsonToken->accessToken;

            $query = "INSERT INTO SIS_TOKENS_APIS_EXTERNAS 
                        (
                            PLATAFORMA,
                            TOKEN,
                            FECHA_OBTENCION,
                            FECHA_CADUCIDAD,
                            RUTA
                        )
                        VALUES
                        (
                            'ALTAN',
                            :P_TOKEN,
                            SYSDATE,
                            SYSDATE+((1/24)*23.97),
                            NULL
                        )";
            $res=OCIParse($db,$query);
            OCIBindByName($res,":P_TOKEN",$access_token);
            OCIExecute($res,OCI_DEFAULT);
            if(OCIError($res))
            {
                $request['ERROR'] = true;
                OCIrollback($db);
            }
            else
            {
                $request['ERROR'] = false;
                OCICommit($db);
            }
            OCIFreeStatement($res);
        }
    }
    
    // UNA VES TENIENDO EL TOKEN ACTIVO SE HACE LA PETICIÓN
    if($access_token!='')
    {
        $saldos['datos']['inicial']=0;
        $saldos['datos']['usados']=0;
        $saldos['datos']['restantes']=0;
        $saldos['sms']['inicial']=0;
        $saldos['sms']['usados']=0;
        $saldos['sms']['restantes']=0;
        $saldos['minutos']['inicial']=0;
        $saldos['minutos']['usados']=0;
        $saldos['minutos']['restantes']=0;
        $saldos['DETALLE']=array();
        $request['IMEI']='';
        $request['ESTATUS']='';
        $request['OFERTA']='';
            $url='https://altanredes-prod.apigee.net/cm/v1/subscribers/'.$msisdn.'/profile';
            $header = array('Authorization: Bearer '.$access_token);
            $curl = curl_init($url);
            //curl_setopt($curl, CURLOPT_POST, false);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            $response = curl_exec($curl);
            $response = json_decode($response);
        if(isset($response->responseSubscriber))
        {
            //print_r($response->responseSubscriber->information->IMEI);
            $request['IMEI']=$response->responseSubscriber->information->IMEI;
            //echo "</br>";
            //print_r($response->responseSubscriber->status->subStatus);
            $request['ESTATUS']=$response->responseSubscriber->status->subStatus;
            $request['OFERTA']='Movilidad';
            //echo "</br>";
            if($response->responseSubscriber->primaryOffering->offeringId == '1000009000')
            {
                // PREACTIVO
                //print_r($response->responseSubscriber->status->subStatus);
                $request['ESTATUS']='PRE ACTIVO';
            }
            if($request['ESTATUS']!='Active')
            {
                $request['OFERTA']='Oferta por Defecto';
            }
            //echo "</br>";
            $request['PAQUETE'] = $response->responseSubscriber->freeUnits;

            // DATOS ARREGLO DE 2 PAGOS
            /*
            $PROMOCION_DATOS = array("FU_Data_Altan-NR_BCN_RS-TT-YT", "FreeData_Altan-RN_P2");
            while(list($i,$campo)=each($request['PAQUETE']))
            {
                if(in_array($campo->name, $PROMOCION_DATOS))
                {
                    $PROMO_MB = $campo->freeUnit->totalAmt;
                    $request['PROMO'][] = $PROMO_MB;
                }
            }
            reset($request['PAQUETE']);
            */

            //PROMOCIONES
            $PROMO_MB = 0;
            $PROMOCION = array();
            //"FreeData_Altan-RN"
            $PROMOCION_DATOS = array("FreeData_Altan-RN_P2", "FU_Data_Altan-NR_BCN_RS-TT-YT");
            while(list($i,$campo)=each($request['PAQUETE']))
            {
                if(in_array($campo->name, $PROMOCION_DATOS))
                {
                    $PROMO_MB += $campo->freeUnit->totalAmt;
                }
                if(substr($campo->detailOfferings[0]->offeringId, 0,3) == "190")
                {
                    $PROMOCION[$campo->name][$campo->detailOfferings[0]->offeringId] = $campo->detailOfferings[0];
                }
            }
            reset($request['PAQUETE']);
            $request['PROMO'] = $PROMO_MB;
            $request['OfferID'] = $PROMOCION;

            while(list($name,$nodo)=each($response->responseSubscriber->freeUnits))
            {
                //print_r($nodo->name);
                //echo "</br>";
                // DATOS
                if($nodo->name=='FreeData_Altan-RN' ||
                   $nodo->name=='FU_Data_Altan-NR-IR_NA' ||
                   $nodo->name=='FU_ThrMBB_Altan-RN_512kbps' ||
                   $nodo->name=='FU_Data_Altan-NR-IR_NA_CT' ||
                   $nodo->name=='FU_Data_Altan-RN_CT')
                {
                    $saldos['datos']['inicial'] += round($nodo->freeUnit->totalAmt / 1000, 2);
                    $saldos['datos']['usados'] += round(($nodo->freeUnit->totalAmt - $nodo->freeUnit->unusedAmt) / 1000, 2);
                    $saldos['datos']['restantes'] += round($nodo->freeUnit->unusedAmt / 1000, 2);
                    while(list($d,$det)=each($nodo->detailOfferings))
                    {
                        $arr=array();
                        $arr['nombre'] = $nodo->name;
                        $arr['inicial'] = round($det->initialAmt / 1000, 2);
                        $arr['usados'] = round(($det->initialAmt - $det->unusedAmt) / 1000, 2);
                        $arr['restantes'] = round($det->unusedAmt / 1000, 2);
                        $saldos['DETALLE']['datos'][]=$arr;
                    }
                }
                // SMS
                if($nodo->name=='FU_Min_Altan-NR-IR-LDI_NA' ||
                   $nodo->name=='FU_Min_Altan-NR-LDI_NA')
                {
                    $saldos['minutos']['inicial'] += $nodo->freeUnit->totalAmt;
                    $saldos['minutos']['usados'] += $nodo->freeUnit->totalAmt - $nodo->freeUnit->unusedAmt;
                    $saldos['minutos']['restantes'] += $nodo->freeUnit->unusedAmt;
                    while(list($d,$det)=each($nodo->detailOfferings))
                    {
                        $arr=array();
                        $arr['nombre'] = $nodo->name;
                        $arr['inicial'] = $det->initialAmt;
                        $arr['usados'] = $det->initialAmt - $det->unusedAmt;
                        $arr['restantes'] = $det->unusedAmt;
                        $saldos['DETALLE']['minutos'][]=$arr;
                    }
                }
                // MINUTOS
                if($nodo->name=='FU_SMS_Altan-NR-LDI_NA' ||
                   $nodo->name=='FU_SMS_Altan-NR-IR-LDI_NA')
                {
                    $saldos['sms']['inicial'] += $nodo->freeUnit->totalAmt;
                    $saldos['sms']['usados'] += $nodo->freeUnit->totalAmt - $nodo->freeUnit->unusedAmt;
                    $saldos['sms']['restantes'] += $nodo->freeUnit->unusedAmt;
                    while(list($d,$det)=each($nodo->detailOfferings))
                    {
                        $arr=array();
                        $arr['nombre'] = $nodo->name;
                        $arr['inicial'] = $det->initialAmt;
                        $arr['usados'] = $det->initialAmt - $det->unusedAmt;
                        $arr['restantes'] = $det->unusedAmt;
                        $saldos['DETALLE']['sms'][]=$arr;
                    }
                }
                //VELOCIDAD REDUCIDA
                if($nodo->name=='FU_ThrMBB_Altan-RN_512kbps_CT')
                {
                    //$saldos['datos']['inicial'] = round($nodo->freeUnit->totalAmt / 1000, 2);
                    //$saldos['datos']['usados'] = round(($nodo->freeUnit->totalAmt - $nodo->freeUnit->unusedAmt) / 1000, 2);
                    //$saldos['datos']['restantes'] = round($nodo->freeUnit->unusedAmt / 1000, 2);
                    while(list($d,$det)=each($nodo->detailOfferings))
                    {
                        $arr=array();
                        $arr['nombre'] = $nodo->name;
                        $arr['inicial'] = round($det->initialAmt / 1000, 2);
                        $arr['usados'] = round(($det->initialAmt - $det->unusedAmt) / 1000, 2);
                        $arr['restantes'] = round($det->unusedAmt / 1000, 2);
                        $saldos['DETALLE']['datos'][]=$arr;
                    }
                }
                //print_r($nodo);
                //echo "</br>";
            }
        }
        else
        {
            //NO EXISTE LINEA
            $request['ESTATUS']='Cancelada';
        }
        //print_r($saldos);
        $request['SALDOS']=$saldos;
        //echo "</br>";
        //print_r($response);
    }
    cerrar_db($db);
    echo json_encode($request);
