-- Galería de fotos y videos por terreno (MySQL 8)
-- La columna terrenos.imagen se mantiene como "foto de portada".

CREATE TABLE terreno_medios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    terreno_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    tipo ENUM('foto', 'video') NOT NULL,
    descripcion VARCHAR(255) NULL,
    tamano_bytes BIGINT NOT NULL DEFAULT 0,
    subido_por_id INT NULL,
    subido_por_nombre VARCHAR(100) NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_medios_terreno (terreno_id),
    CONSTRAINT fk_medios_terreno FOREIGN KEY (terreno_id) REFERENCES terrenos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Pasar la foto que ya tenía cada terreno a la galería
INSERT INTO terreno_medios (terreno_id, archivo, tipo, descripcion, subido_por_id, subido_por_nombre, creado_en)
SELECT t.id, t.imagen, 'foto', 'Foto principal', t.creado_por_id, u.nombre, t.creado_en
FROM terrenos t JOIN usuarios u ON u.id = t.creado_por_id
WHERE t.imagen IS NOT NULL AND t.imagen <> '';
