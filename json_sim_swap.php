<?php
    include("../../../include/conexion.php");
    function genera_token()
    {
        //$db=conecta_db();
        $db=conecta_desarrollo();
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
        curl_close($curljson);
        //print_r(json_decode($jsonResult));
        return json_decode($jsonResult);        
    }
    //$contrato=json_decode($_POST['contrato_prin']);
    $contrato='';
    if(isset($_POST['usuario']))
    {
        $usuario=$_POST['usuario'];
    }
    else
    {
        $usuario='SISTEMAS';
    }
    $msisdn=json_decode($_POST['msisdn']);
    $newIccid=json_decode($_POST['newIccid']);
    $request = array();
    //$db=conecta_db();
    $db=conecta_desarrollo();
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
    
    // SI NO HAY TOKEN ACTIVO SE GENERA UNO
    if($access_token=='')
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
        $parametros = array("changeSubscriberSIM" => array
                            (
                                "newIccid" => $newIccid
                            ));

        $request['RESPUESTA']['msisdn']='';
        $request['RESPUESTA']['effectiveDate']='';
        $request['RESPUESTA']['ORDEN_ID']='';

        //PROD 
        //$url='https://altanredes-prod.apigee.net/cm/v1/subscribers/'.$msisdn.'';
        //TEST 
        $url='https://altanredes-prod.apigee.net/cm-sandbox/v1/subscribers/'.$msisdn.'';
        $header = array('Authorization: Bearer '.$access_token,'Content-Type: application/json');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL,$url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($parametros));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        $response = curl_exec($curl);
        curl_close($curl);
        $response = json_decode($response);
        //Existe respuesta del API
        //{"msisdn":"5527614100","effectiveDate":"20230310100708","order":{"id":"1100201"}}
        if(isset($response->effectiveDate))
        {
            $request['RESPUESTA']=$response;
            //INSERT EN TABLA ALTAN_SOLICITUDES_SERVICIOS
            $tipo='sim_swap';
            $query_altan = "INSERT INTO ALTAN_SOLICITUDES_SERVICIOS (ID_SOLICITUD,
                                        FECHA,
                                        USUARIO,
                                        TIPO,
                                        CONTRATO_PRIN,
                                        NUMERO_REDPOTENCIA,
                                        IMEI,
                                        ORDEN_ID,
                                        OLDMSISDN,
                                        NEWMSISDN,
                                        FECHA_RESPUESTA)
                VALUES ( (SELECT (NVL(MAX(ID_SOLICITUD),0)+1) 
                        FROM   ALTAN_SOLICITUDES_SERVICIOS),
                        SYSDATE,
                        :P_USUARIO,
                        :P_TIPO,
                        :P_CONTRATO_PRIN,
                        :P_NUMERO,
                        '',
                        :P_ORDEN_ID,
                        '',
                        '',
                        TO_DATE(:P_FECHA_RESPUESTA, 'YYYY/MM/DD HH24:MI:SS')
                        )";
            $res=OCIParse($db,$query_altan);
            OCIBindByName($res,":P_USUARIO",$usuario);
            OCIBindByName($res,":P_TIPO",$tipo);
            OCIBindByName($res,":P_CONTRATO_PRIN",$contrato);
            OCIBindByName($res,":P_NUMERO",$msisdn);
            OCIBindByName($res,":P_ORDEN_ID",$response->order->id);
            OCIBindByName($res,":P_FECHA_RESPUESTA",$response->effectiveDate);
            OCIExecute($res,OCI_DEFAULT);
            if(OCIError($res))
            {
                $request['ERROR'] = true;
                OCIrollback($db);
            }
            else
            {
                $request['ERROR'] = false;
                //OCICommit($db);
            }
            OCIFreeStatement($res);
        }
        elseif($response->errorCode == 500)
        {
            //error en el servidor de altan realizar la operacion mas tarde y/o reportar a altan
            $request['ERROR'] = $response->errorCode;
        }
    }
    cerrar_db($db);
    //$request['RESULT'] = http_response_code();
    echo json_encode($request);
?>
