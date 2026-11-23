<?php
declare(strict_types=1);

/**
 * CLI-only, idempotent protection for the primary HLS Music Lab administrator.
 * Uses the immutable account ID, not the username.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/auth.php';

try {
    $pdo = lab_auth_pdo();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 15000');

    $id = LAB_PROTECTED_ADMIN_ID;
    $pdo->beginTransaction();

    $q = $pdo->prepare('SELECT id, username, role, is_active, can_manage_albums FROM users WHERE id = ? LIMIT 1');
    $q->execute([$id]);
    $account = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();

    if (!$account) {
        throw new RuntimeException(
            'No existe la cuenta con el ID protegido configurado. No se modificó ningún usuario. Verifica LAB_PROTECTED_ADMIN_ID en auth.php.'
        );
    }

    // Reparar coherencia sin borrar ni recrear la cuenta ni cambiar su contraseña.
    // En esta aplicación el rol admin implica gestión de álbumes; sincronizar el indicador.
    $pdo->prepare("UPDATE users SET can_manage_albums = 1 WHERE role = 'admin'")->execute();

    $q = $pdo->prepare(
        "UPDATE users
         SET role = 'admin', is_active = 1, can_manage_albums = 1, updated_at = ?
         WHERE id = ?"
    );
    $q->execute([time(), $id]);
    $q->closeCursor();

    // La protección en la base complementa los controles PHP y la interfaz.
    $quotedId = $pdo->quote($id);
    $pdo->exec('DROP TRIGGER IF EXISTS protect_primary_admin_update');
    $pdo->exec('DROP TRIGGER IF EXISTS protect_primary_admin_delete');

    $pdo->exec(
        "CREATE TRIGGER protect_primary_admin_update
         BEFORE UPDATE OF id, role, is_active, can_manage_albums ON users
         FOR EACH ROW
         WHEN OLD.id = $quotedId
          AND (
            NEW.id <> $quotedId
            OR NEW.role <> 'admin'
            OR NEW.is_active <> 1
            OR NEW.can_manage_albums <> 1
          )
         BEGIN
            SELECT RAISE(ABORT, 'La cuenta administradora principal está protegida');
         END"
    );

    $pdo->exec(
        "CREATE TRIGGER protect_primary_admin_delete
         BEFORE DELETE ON users
         FOR EACH ROW
         WHEN OLD.id = $quotedId
         BEGIN
            SELECT RAISE(ABORT, 'La cuenta administradora principal no se puede eliminar');
         END"
    );

    $pdo->commit();

    $q = $pdo->prepare('SELECT id, username, role, is_active, can_manage_albums FROM users WHERE id = ?');
    $q->execute([$id]);
    $verified = $q->fetch(PDO::FETCH_ASSOC);
    $q->closeCursor();

    if (!$verified
        || (string)$verified['role'] !== 'admin'
        || (int)$verified['is_active'] !== 1
        || (int)$verified['can_manage_albums'] !== 1) {
        throw new RuntimeException('La verificación posterior a la migración no pasó.');
    }

    echo "Cuenta principal protegida correctamente.\n";
    echo "Usuario: " . $verified['username'] . "\n";
    echo "ID protegido: " . $verified['id'] . "\n";
    echo "Rol: admin | Activa: sí | Gestionar álbumes: sí\n";
    echo "Triggers de protección: instalados.\n";
    echo "La cuenta y su contraseña existentes se conservaron.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
