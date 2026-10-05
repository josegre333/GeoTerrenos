-- Módulo de Consulta Catastral para GeoTerrenos (MySQL 8)
-- Agrega el código catastral (número predial), la clase de predio y la tabla de construcciones.

ALTER TABLE terrenos
    ADD COLUMN codigo_catastral VARCHAR(40) NULL AFTER id,
    ADD COLUMN clase_predio ENUM('urbano', 'rural') NOT NULL DEFAULT 'urbano' AFTER codigo_catastral,
    ADD UNIQUE KEY uq_terrenos_codigo_catastral (codigo_catastral);

-- Asigna un código a los terrenos que ya existen (GT-000001, GT-000002, ...)
UPDATE terrenos SET codigo_catastral = CONCAT('GT-', LPAD(id, 6, '0')) WHERE codigo_catastral IS NULL;

CREATE TABLE construcciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    terreno_id INT NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    uso VARCHAR(60) NULL,
    pisos INT NOT NULL DEFAULT 1,
    area_construida DECIMAL(12, 2) NOT NULL DEFAULT 0,
    anio_construccion SMALLINT NULL,
    material VARCHAR(60) NULL,
    estado_conservacion ENUM('bueno', 'regular', 'malo') NOT NULL DEFAULT 'bueno',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_construcciones_terreno FOREIGN KEY (terreno_id) REFERENCES terrenos (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
