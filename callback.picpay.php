<?php
/**
 * AUTHOR: DANIEL CABREDO
 * DATE: 20-03-2020
 * TODO: Atualiza o status do pagamento via callback da Picpay
 * https://ecommerce.picpay.com/doc/#operation/postPayments
 */

# Includes
require_once '../../../../config.php';
require_once '../../../../admin/classes/class.pedidos.php';
require_once('../../../../classes/functions.php');

# DISPLAY ERRORS
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('max_execution_time', 0);
ini_set('date.timezone', 'America/Sao_Paulo');

# CONVERTER O REQUEST JSON EM OBJETO
$request = (object)json_decode(file_get_contents('php://input'), TRUE);

if (!empty($request->referenceId)){
    query("BEGIN WORK");

    $sql = "SELECT p.id, p.reve_cod FROM pedidos p WHERE p.id_formapagto = 303 AND p.id_situacao = 1 AND p.id = " . $request->referenceId;
    $rsPedido = query($sql);

    if ($rsPedido === false){
        query("ROLLBACK WORK");
        reportaErroEmailPicpay('CONSULTA', $request, $sql);
        exit();
    }else if (num_rows($rsPedido) > 0){
        $id_pedido = result($rsPedido, 0, 'id') ? result($rsPedido, 0, 'id') : '';
        $id_loja = result($rsPedido, 0, 'reve_cod') ? result($rsPedido, 0, 'reve_cod') : '';

        if (empty($id_loja) || empty($id_pedido)){
            $sql .= "<br><br> ID LOJA: ". $id_loja . " - ID PEDIDO: " . $id_pedido;
            query("ROLLBACK WORK");
            reportaErroEmailPicpay('CONSULTA', $request, $sql);
            exit();
        }

        $sql = "UPDATE intermediador set status = 'APROVADO', id_autorizacao = '". $request->authorizationId ."', data_atualizacao = '" . date("Y-m-d") . "', hora_atualizacao = '" . date("H:i:s") . "'
                 WHERE id_pedido = ". $id_pedido ." AND id_forma_pagamento = 303 AND id_loja = " . $id_loja;
        $rsUpdateInt = query($sql);

        if ($rsUpdateInt === false){
            query("ROLLBACK WORK");
            reportaErroEmailPicpay('ATUALIZACAO', $request, $sql);
            exit();
        }

        $pedido = new Pedidos(); #ALTERACAO DE PEDIDOS

        #Atualizando Informação do Pedido
        $pedido->SetIdPedido($id_pedido);
        $pedido->SetIdLoja($id_loja);
        $updDatabase = $pedido->AtualizaSituacao(2, "Pedido realizado via Picpay.", "t", 1, "", "f");
    } else {
        query("ROLLBACK WORK");
        reportaErroEmailPicpay('CONSULTA', $request, $sql . " <br><br> NENHUMA LINHA ENCONTRADA NA CONSULTA");
        exit();
    }

    query("COMMIT WORK");

} else {
    $requestJSON = str_replace("'", '"', json_encode($request));

    query("INSERT INTO log_erros (post_erro, tipo_erro) VALUES ('{$requestJSON}', 'PICPAY CALLBACK - ERRO')");
}

