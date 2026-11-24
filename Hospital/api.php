<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function currentState(PDO $pdo): array
{
    $medicines = $pdo->query(
        'SELECT id, nome, principio_ativo, apresentacao, categoria, unidade, quantidade,
                estoque_minimo, lote, validade, fornecedor, atualizado_em
         FROM medicamentos'
    )->fetchAll();
    $movements = $pdo->query(
        'SELECT id, medicamento_id, medicamento_nome, tipo, quantidade, saldo_apos,
                responsavel, observacao, data_hora
         FROM movimentacoes ORDER BY data_hora'
    )->fetchAll();

    return [
        'medicines' => array_map(static fn(array $item): array => [
            'id' => $item['id'],
            'name' => $item['nome'],
            'activeIngredient' => $item['principio_ativo'],
            'presentation' => $item['apresentacao'],
            'category' => $item['categoria'],
            'unit' => $item['unidade'],
            'quantity' => (int) $item['quantidade'],
            'minimum' => (int) $item['estoque_minimo'],
            'lot' => $item['lote'],
            'expiry' => $item['validade'] ?? '',
            'supplier' => $item['fornecedor'],
            'updatedAt' => $item['atualizado_em'],
        ], $medicines),
        'movements' => array_map(static fn(array $item): array => [
            'id' => $item['id'],
            'medicineId' => $item['medicamento_id'] ?? '',
            'medicineName' => $item['medicamento_nome'],
            'type' => $item['tipo'],
            'quantity' => (int) $item['quantidade'],
            'balance' => (int) $item['saldo_apos'],
            'responsible' => $item['responsavel'],
            'notes' => $item['observacao'],
            'date' => $item['data_hora'],
        ], $movements),
    ];
}

function requiredString(array $input, string $key, int $maxLength): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value) || trim($value) === '' || textLength(trim($value)) > $maxLength) {
        throw new InvalidArgumentException("O campo {$key} é obrigatório ou excede o limite permitido.");
    }
    return trim($value);
}

function optionalString(array $input, string $key, int $maxLength): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || textLength($value) > $maxLength) {
        throw new InvalidArgumentException("O campo {$key} excede o limite permitido.");
    }
    return trim($value);
}

function textLength(string $value): int
{
    $length = preg_match_all('/./us', $value);
    if ($length === false) {
        throw new InvalidArgumentException('O texto enviado não está em UTF-8 válido.');
    }
    return $length;
}

function integerValue(array $input, string $key, int $minimum): int
{
    $value = $input[$key] ?? null;
    if (!is_int($value) || $value < $minimum) {
        throw new InvalidArgumentException("O campo {$key} deve ser um número inteiro válido.");
    }
    return $value;
}

function importLegacyState(PDO $pdo, array $legacy): bool
{
    if (!is_array($legacy['medicines'] ?? null) || !is_array($legacy['movements'] ?? null)) {
        throw new InvalidArgumentException('Os dados antigos do navegador estão em formato inválido.');
    }

    $pdo->beginTransaction();
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM medicamentos')->fetchColumn()
            + (int) $pdo->query('SELECT COUNT(*) FROM movimentacoes')->fetchColumn();
        if ($count !== 0) {
            $pdo->commit();
            return false;
        }

        $insertMedicine = $pdo->prepare(
            'INSERT INTO medicamentos
             (id, nome, principio_ativo, apresentacao, categoria, unidade, quantidade,
              estoque_minimo, lote, validade, fornecedor, atualizado_em)
             VALUES
             (:id, :name, :active, :presentation, :category, :unit, :quantity,
              :minimum, :lot, :expiry, :supplier, :updated)'
        );
        foreach ($legacy['medicines'] as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Há um medicamento antigo em formato inválido.');
            }
            $insertMedicine->execute([
                ':id' => requiredString($item, 'id', 100),
                ':name' => requiredString($item, 'name', 100),
                ':active' => optionalString($item, 'activeIngredient', 100),
                ':presentation' => requiredString($item, 'presentation', 80),
                ':category' => requiredString($item, 'category', 60),
                ':unit' => requiredString($item, 'unit', 20),
                ':quantity' => integerValue($item, 'quantity', 0),
                ':minimum' => integerValue($item, 'minimum', 0),
                ':lot' => optionalString($item, 'lot', 60),
                ':expiry' => optionalString($item, 'expiry', 10) ?: null,
                ':supplier' => optionalString($item, 'supplier', 100),
                ':updated' => optionalString($item, 'updatedAt', 40) ?: gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }

        $insertMovement = $pdo->prepare(
            'INSERT INTO movimentacoes
             (id, medicamento_id, medicamento_nome, tipo, quantidade, saldo_apos,
              responsavel, observacao, data_hora)
             VALUES (:id, NULL, :name, :type, :quantity, :balance, :responsible, :notes, :date)'
        );
        foreach ($legacy['movements'] as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Há uma movimentação antiga em formato inválido.');
            }
            $type = requiredString($item, 'type', 20);
            if (!in_array($type, ['Entrada', 'Saída', 'Ajuste'], true)) {
                throw new InvalidArgumentException('Há uma movimentação antiga com tipo inválido.');
            }
            $insertMovement->execute([
                ':id' => requiredString($item, 'id', 100),
                ':name' => requiredString($item, 'medicineName', 100),
                ':type' => $type,
                ':quantity' => integerValue($item, 'quantity', 1),
                ':balance' => integerValue($item, 'balance', 0),
                ':responsible' => optionalString($item, 'responsible', 80),
                ':notes' => optionalString($item, 'notes', 300),
                ':date' => optionalString($item, 'date', 40) ?: gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

try {
    $pdo = hospitalDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        respond(200, ['state' => currentState($pdo)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Método não permitido.']);
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new InvalidArgumentException('Requisição inválida.');
    }
    $action = $body['action'] ?? '';

    if ($action === 'import') {
        if (!is_array($body['state'] ?? null)) {
            throw new InvalidArgumentException('Dados de importação ausentes.');
        }
        $imported = importLegacyState($pdo, $body['state']);
        respond(200, ['state' => currentState($pdo), 'imported' => $imported]);
    }

    if ($action === 'saveMedicine') {
        $item = $body['medicine'] ?? null;
        if (!is_array($item)) {
            throw new InvalidArgumentException('Dados do medicamento ausentes.');
        }
        $fields = [
            ':id' => requiredString($item, 'id', 100),
            ':name' => requiredString($item, 'name', 100),
            ':active' => optionalString($item, 'activeIngredient', 100),
            ':presentation' => requiredString($item, 'presentation', 80),
            ':category' => requiredString($item, 'category', 60),
            ':unit' => requiredString($item, 'unit', 20),
            ':minimum' => integerValue($item, 'minimum', 0),
            ':lot' => optionalString($item, 'lot', 60),
            ':expiry' => optionalString($item, 'expiry', 10) ?: null,
            ':supplier' => optionalString($item, 'supplier', 100),
        ];
        if (!in_array($fields[':category'], ['Analgesia', 'Antibiótico', 'Anti-inflamatório', 'Cardiovascular', 'Dermatologia', 'Gastroenterologia', 'Material hospitalar', 'Outros', 'Respiratório'], true)) {
            throw new InvalidArgumentException('Categoria inválida.');
        }
        if (!in_array($fields[':unit'], ['unidades', 'caixas', 'frascos', 'ampolas', 'cartelas', 'pacotes', 'pares', 'litros', 'mL', 'g', 'kg'], true)) {
            throw new InvalidArgumentException('Unidade de medida inválida.');
        }

        $existing = $pdo->prepare('SELECT 1 FROM medicamentos WHERE id = :id');
        $existing->execute([':id' => $fields[':id']]);
        if ($existing->fetchColumn()) {
            $update = $pdo->prepare(
                'UPDATE medicamentos SET nome=:name, principio_ativo=:active,
                 apresentacao=:presentation, categoria=:category, unidade=:unit,
                 estoque_minimo=:minimum, lote=:lot, validade=:expiry, fornecedor=:supplier,
                 atualizado_em=:updated WHERE id=:id'
            );
            $update->execute($fields + [':updated' => gmdate('Y-m-d\TH:i:s\Z')]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO medicamentos
                 (id, nome, principio_ativo, apresentacao, categoria, unidade, quantidade,
                  estoque_minimo, lote, validade, fornecedor, atualizado_em)
                 VALUES (:id, :name, :active, :presentation, :category, :unit, :quantity,
                         :minimum, :lot, :expiry, :supplier, :updated)'
            );
            $insert->execute($fields + [
                ':quantity' => integerValue($item, 'quantity', 0),
                ':updated' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        }
    } elseif ($action === 'deleteMedicine') {
        $id = requiredString($body, 'id', 100);
        $delete = $pdo->prepare('DELETE FROM medicamentos WHERE id = :id');
        $delete->execute([':id' => $id]);
        if ($delete->rowCount() === 0) {
            respond(404, ['error' => 'Medicamento não encontrado.']);
        }
    } elseif ($action === 'addMovement') {
        $item = $body['movement'] ?? null;
        if (!is_array($item)) {
            throw new InvalidArgumentException('Dados da movimentação ausentes.');
        }
        $medicineId = requiredString($item, 'medicineId', 100);
        $type = requiredString($item, 'type', 20);
        if (!in_array($type, ['Entrada', 'Saída', 'Ajuste'], true)) {
            throw new InvalidArgumentException('Tipo de movimentação inválido.');
        }
        $quantity = integerValue($item, 'quantity', 1);
        $medicine = $pdo->prepare('SELECT nome, quantidade FROM medicamentos WHERE id = :id');
        $medicine->execute([':id' => $medicineId]);
        $current = $medicine->fetch();
        if (!$current) {
            respond(404, ['error' => 'Medicamento não encontrado. Atualize a página e tente novamente.']);
        }
        if ($type === 'Saída' && $quantity > (int) $current['quantidade']) {
            respond(422, ['error' => 'Estoque insuficiente para registrar esta saída.']);
        }
        $insert = $pdo->prepare(
            'INSERT INTO movimentacoes
             (id, medicamento_id, medicamento_nome, tipo, quantidade, responsavel, observacao, data_hora)
             VALUES (:id, :medicine_id, :name, :type, :quantity, :responsible, :notes, :date)'
        );
        $insert->execute([
            ':id' => requiredString($item, 'id', 100),
            ':medicine_id' => $medicineId,
            ':name' => $current['nome'],
            ':type' => $type,
            ':quantity' => $quantity,
            ':responsible' => optionalString($item, 'responsible', 80),
            ':notes' => optionalString($item, 'notes', 300),
            ':date' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    } else {
        throw new InvalidArgumentException('Ação não reconhecida.');
    }

    respond(200, ['state' => currentState($pdo)]);
} catch (JsonException | InvalidArgumentException $error) {
    respond(422, ['error' => $error->getMessage()]);
} catch (PDOException $error) {
    error_log('Erro no banco de dados do estoque: ' . $error->getMessage());
    $message = match (true) {
        str_contains($error->getMessage(), 'UNIQUE constraint failed') => 'Já existe um medicamento com esse nome e apresentação.',
        str_contains($error->getMessage(), 'Estoque insuficiente') => 'Estoque insuficiente para registrar esta saída.',
        default => 'Não foi possível concluir a operação no banco de dados.',
    };
    respond(409, ['error' => $message]);
} catch (Throwable $error) {
    error_log('Erro na API do estoque: ' . $error->getMessage());
    respond(500, ['error' => 'O servidor não conseguiu acessar o banco de dados. Confira os logs do serviço app.']);
}
