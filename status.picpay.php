<?php
/**
 * AUTHOR: DANIEL CABREDO
 * DATE: 20-03-2020
 * TODO: Verifica status pedido no Picpay.
 * https://ecommerce.picpay.com/doc/#tag/Status
 */

require_once('../../../../config.php');
require_once('../../../../classes/functions.php');

# DISPLAY ERRORS
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('max_execution_time', 0);
ini_set('date.timezone', 'America/Sao_Paulo');

# CASO QUEIRA REALIZAR CONSULTA DE STATUS FILTRANDO PELO ID PEDIDO OU ID LOJA
$loja = isset($_POST["l"]) ? ' AND rf.reve_cod = ' . intval($_POST["l"]) : '';
$pedido = isset($_POST["p"]) ? ' AND p.id = ' . $_POST["p"] : '';

# BUSCA LOJAS COM O INTERMEDIADOR PICPAY ATIVO
$sql = "SELECT f.nome, rf.reve_cod
		FROM lojas rf
		JOIN forma_pagamento f on (rf.id_formapagto = f.id)
		WHERE f.id = 303
		ORDER BY f.nome";
$rsLojaPicpay = query($sql);

echo "<h1>VERIFICA STATUS DE PEDIDOS NO PICPAY</h1><br><br><br>";

if (num_rows($rsLojaPicpay) > 0){
    $lojasPicpay = result_all($rsLojaPicpay);

    foreach ($lojasPicpay as $lojaPicpay) {
        $loja = intval($lojaPicpay["reve_cod"]);

        echo "<hr>Loja {$loja} : <br>";

        $lojaCamposAdd = loja_campos_adicionais($loja);
        $token = $lojaCamposAdd["x-picpay-token"];

        if (!empty($token)) {
            $sql = "SELECT p.id 
                     FROM pedidos p 
                     WHERE p.id_formapagto = 303 AND p.id_situacao = 1 AND p.data > '" . date('Y-m-d', strtotime('-6 months')) . "' AND p.reve_cod = " . $loja . $pedido . " LIMIT 150";
            $rsPedido = query($sql);

            if (num_rows($rsPedido) > 0){
                $pedidosPicpay = result_all($rsPedido);

                foreach ($pedidosPicpay as $pedidoPicpay){
                    $id = $pedidoPicpay["id"];

                    # PREPARA HEADERS COM TOKEN DA PICPAY E CONTENT TYPE
                    $headers = Array(
                        "x-picpay-token: {$token}",
                        "Content-Type: application/json"
                    );

                    # REALIZA REQUISIÇÃO PARA API PICPAY
                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, 'https://appws.picpay.com/ecommerce/public/payments/' . $id . '/status');
                    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

                    $response = curl_exec($curl);

//                    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                    curl_close($curl);

                    echo ($response)."<br><br>";
                }
            }else
                echo "Sem pedido do picpay com o status 'Aguardando pagamento' <br>";
        }else
            echo "Sem token configurado, favor configure.<br>";

    }
}