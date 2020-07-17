<?php
/**
 * AUTHOR: DANIEL CABREDO
 * DATE: 20-03-2020
 * TODO: Envia pedido para Picpay e salva no banco de dados [ECOMPLETO] url pagamento. Caso j� tenha o pedido enviado, apenas redireciona para url de pagamento.
 * https://ecommerce.picpay.com/doc/#operation/postPayments
 */

require_once('../../../../config.php');
require_once('../../../../classes/functions.php');

# Display errors e Date timezone
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('max_execution_time', 0);
ini_set('date.timezone', 'America/Sao_Paulo');

$loja = isset($_POST["l"]) ? intval($_POST["l"]) : '';
$pedido = isset($_POST["p"]) ? $_POST["p"] : '';

# VALIDA SE O PEDIDO E LOJA FOI ENVIADO POR PARAMETRO [POST]
if (empty($loja) && empty($pedido))
    exit('ERRO: INFORME LOJA E PEDIDO PARA CONTINUAR COM O PROCESSAMENTO DOS DADOS PARA ENVIO PICPAY.');

$lojaCamposAdd = loja_campos_adicionais($loja);

$token = $lojaCamposAdd["x-picpay-token"];

# VERIFICA SE EXISTE O TOKEN PICPAY CONFIGURADO PARA LOJA
if (!empty($token)) {
    # VERIFICA NOVAMENTE SE O PEDIDO FOI ENVIADO POR PARAMETRO [POST]
    if (!empty($pedido)) {
        $sql = "SELECT retorno FROM intermediador WHERE id_pedido = {$pedido} AND id_loja = {$loja}";
        $resultPedidoEnv = query($sql);

        if (num_rows($resultPedidoEnv) > 0) {
            $retornoPicpay = json_decode(result($resultPedidoEnv, 0, 'retorno'));

            header('Location: ' . $retornoPicpay->paymentUrl);
            exit();
        }

        $sql = "SELECT pes.pess_nome, c.clie_cpf_cnpj, c.clie_email, tel.tele_fone, p.valor_total
                FROM pedidos_loja p
                JOIN clientes c ON (c.clie_cod = p.id_cliente AND c.clie_cpf_cnpj != '' AND c.clie_email != '')
                JOIN pessoas pes ON (pes.pess_cod = c.pess_cod)
                JOIN telefones tel ON (tel.pess_cod = pes.pess_cod AND tel.tele_fone != '')
                WHERE p.id = {$pedido} AND p.id_situacao = 1 LIMIT 1";
        $resultPedido = query($sql);

        if (num_rows($resultPedido) > 0) {
            # POPULA DADOS DO BANCO EM VARIAVEIS
            $firstName = primeiroNome(trim(result($resultPedido, 0, 'pess_nome')));
            $lastName = ultimoNome(trim(result($resultPedido, 0, 'pess_nome')));
            $cpf_cnpj = fu_formata_cpf_cnpj(result($resultPedido, 0, 'clie_cpf_cnpj'));
            $email = result($resultPedido, 0, 'clie_email');
            $telefone = '+55 ' . result($resultPedido, 0, 'tele_fone');
            $total = floatval(result($resultPedido, 0, 'valor_total'));

            # CAPTURA URL LOJA PARA RETORNAR APÓS FAZER O PAGAMENTO NO PICPAY
            $sql = "SELECT url FROM revendas WHERE reve_cod = {$loja}";
            $rsUrl = query($sql);

            if (num_rows($rsUrl) > 0)
                $urlReturn = (result($rsUrl, 0, 'url')) ? 'http://' . result($rsUrl, 0, 'url') . '/f_order.php?id=' . md5($pedido) : '';

            # PREPARA HEADERS COM TOKEN DA PICPAY E CONTENT TYPE
            $headers = Array(
                "x-picpay-token: {$token}",
                "Content-Type: application/json"
            );

            # PREPARA OBJETO PARA ENVIAR A PICPAY COM OS DADOS DO USU�RIO
            $order = new stdClass();
            $order->referenceId = $pedido;
            $order->callbackUrl = 'http://www.ecompleto.com.br/libs/php/classes/picpay/callback.picpay.php';
            if (isset($urlReturn))
                $order->returnUrl = $urlReturn;
            $order->value = $total;
            $order->buyer = array(
                'firstName' => json_encode($firstName),
                'lastName' => json_encode($lastName),
                'document' => $cpf_cnpj,
                'email' => $email,
                'phone' => $telefone
            );

            # REALIZA REQUISIÇÃO PARA API PICPAY
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://appws.picpay.com/ecommerce/public/payments');
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($order));
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

            $response = curl_exec($curl);

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            $responseDecode = json_decode($response);

            # PEDIDO ENVIADO AO PICPAY - CASO O CLIENTE N�O PAGUE AT� A DATA DE EXPIRAR, CANCELA
            if (intval($httpCode) === 200 && isset($response)) {
                $sql = "INSERT INTO intermediador (id_pedido, id_forma_pagamento, status, id_loja, data_atualizacao, hora_atualizacao, retorno) 
                        VALUES ('{$pedido}', '303', 'ENVIADO', '{$loja}', '" . date("Y-m-d") . "', '" . date("H:i:s") . "', '" . str_replace("'", "", $response) . "')";
                query($sql);

                header('Location: ' . $responseDecode->paymentUrl);
                exit();
            } else if (intval($httpCode) === 401) { # TOKEN INVALIDO
                exit("ERRO: CONFIGURAÇÕES DA LOJA ESTÃO INCORRETOS, CONTATE O SUPORTE PARA CORREÇÃO [2].");
            } else if (intval($httpCode) === 422) { # ERRO EM PARÂMETROS ENVIADOS
                echo "ERRO: {$responseDecode->message}. <br>";
                #DEBUG ERRORS -> FIELDS
//                if (isset($responseDecode->errors)) {
//                    foreach ($responseDecode->errors as $error)
//                        $errorMsg = strtoupper($error['message']) . "<br>";
//                }
            } else if (intval($httpCode) === 500){ # Problema geral na PICPAY, verifique se a transação foi criada já.
                exit("ERRO: DESCONHECIDO, TENTE MAIS TARDE.");
            }
        } else {
            exit('ERRO: PEDIDO NÃO ENCONTRADO.');
        }
    } else {
        exit('ERRO: INFORME PEDIDO PARA REALIZAR O PAGAMENTO.');
    }
} else {
    exit("ERRO: CONFIGURAÇÕES DA LOJA ESTÃO INCORRETOS, CONTATE O SUPORTE PARA CORREÇÃO [1].");
}


