<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once '../includes/db.php';
require_once 'check_session.php';
requerirAdmin();

$data = json_decode(file_get_contents("php://input"), true);
$id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);

if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de producto requerido o inválido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Eliminar de la lista de favoritos si existe
    try {
        $stmtFav = $pdo->prepare("DELETE FROM favoritos WHERE producto_id = ?");
        $stmtFav->execute([$id]);
    } catch (Exception $e) {}

    // 2. Asegurar que las columnas de llaves foráneas en detalles permitan NULL
    try {
        $pdo->exec("ALTER TABLE venta_detalles MODIFY COLUMN producto_id INT NULL");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE compra_detalles MODIFY COLUMN producto_id INT NULL");
    } catch (Exception $e) {}

    // 3. Desvincular de detalles de ventas y compras conservando los registros e historial financiero
    try {
        $stmtVd = $pdo->prepare("UPDATE venta_detalles SET producto_id = NULL WHERE producto_id = ?");
        $stmtVd->execute([$id]);
    } catch (Exception $e) {}

    try {
        $stmtCd = $pdo->prepare("UPDATE compra_detalles SET producto_id = NULL WHERE producto_id = ?");
        $stmtCd->execute([$id]);
    } catch (Exception $e) {}

    // 4. Eliminar el producto de la base de datos
    $stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Producto eliminado exitosamente.'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Fallback de seguridad: despublicar y retirar de la tienda activa si persiste alguna restricción
    try {
        $stmtFall = $pdo->prepare("UPDATE productos SET publicado = 0, stock = 0 WHERE id = ?");
        $stmtFall->execute([$id]);

        echo json_encode([
            'success' => true, 
            'message' => 'El producto fue retirado y archivado del inventario.'
        ]);
    } catch (Exception $e2) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'error' => 'No se pudo eliminar el producto: ' . $e->getMessage()
        ]);
    }
}
?>
