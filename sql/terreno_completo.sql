-- Datos completos del terreno (MySQL 8): propiedad, linderos, uso, servicios,
-- condiciones físicas y riesgo, valor y situación legal, población e inspección.

ALTER TABLE terrenos
    -- Propiedad y tenencia
    ADD COLUMN naturaleza ENUM('privado', 'publico') NOT NULL DEFAULT 'privado',
    ADD COLUMN nivel_publico ENUM('nacional', 'estadal', 'municipal', 'ejido') NULL,
    ADD COLUMN organismo_responsable VARCHAR(150) NULL,
    ADD COLUMN regimen_tenencia ENUM('propio', 'arrendado', 'comodato', 'sucesion', 'ejido', 'baldio', 'ocupacion', 'otro') NULL,
    ADD COLUMN propietario_nombre VARCHAR(150) NULL,
    ADD COLUMN propietario_documento VARCHAR(30) NULL,
    ADD COLUMN propietario_telefono VARCHAR(30) NULL,
    -- Documento de propiedad
    ADD COLUMN doc_numero VARCHAR(40) NULL,
    ADD COLUMN doc_tomo VARCHAR(20) NULL,
    ADD COLUMN doc_folio VARCHAR(20) NULL,
    ADD COLUMN doc_protocolo VARCHAR(40) NULL,
    ADD COLUMN doc_fecha DATE NULL,
    ADD COLUMN doc_oficina VARCHAR(150) NULL,
    ADD COLUMN doc_archivo VARCHAR(255) NULL,
    -- Linderos y forma
    ADD COLUMN poligono_geojson MEDIUMTEXT NULL,
    ADD COLUMN perimetro_m DECIMAL(12, 2) NULL,
    ADD COLUMN lindero_norte VARCHAR(255) NULL,
    ADD COLUMN lindero_norte_m DECIMAL(10, 2) NULL,
    ADD COLUMN lindero_sur VARCHAR(255) NULL,
    ADD COLUMN lindero_sur_m DECIMAL(10, 2) NULL,
    ADD COLUMN lindero_este VARCHAR(255) NULL,
    ADD COLUMN lindero_este_m DECIMAL(10, 2) NULL,
    ADD COLUMN lindero_oeste VARCHAR(255) NULL,
    ADD COLUMN lindero_oeste_m DECIMAL(10, 2) NULL,
    -- Uso y zonificación
    ADD COLUMN uso_actual ENUM('residencial', 'comercial', 'agricola', 'industrial', 'institucional', 'recreacional', 'mixto', 'sin_uso') NULL,
    ADD COLUMN zonificacion VARCHAR(100) NULL,
    -- Servicios públicos
    ADD COLUMN srv_electricidad TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN srv_agua TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN srv_cloacas TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN srv_gas TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN srv_internet TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN srv_aseo TINYINT(1) NOT NULL DEFAULT 0,
    -- Condiciones físicas y riesgo
    ADD COLUMN topografia ENUM('plano', 'ondulado', 'pendiente_moderada', 'pendiente_fuerte') NULL,
    ADD COLUMN vegetacion ENUM('ninguna', 'baja', 'media', 'densa') NULL,
    ADD COLUMN acceso_vial ENUM('asfaltada', 'tierra', 'peatonal', 'sin_acceso') NULL,
    ADD COLUMN riesgos VARCHAR(255) NULL,
    ADD COLUMN riesgo_descripcion TEXT NULL,
    -- Valor y situación legal
    ADD COLUMN valor_catastral DECIMAL(15, 2) NULL,
    ADD COLUMN valor_moneda ENUM('USD', 'VES') NOT NULL DEFAULT 'USD',
    ADD COLUMN valor_fecha DATE NULL,
    ADD COLUMN solvencia_inmobiliaria ENUM('solvente', 'no_solvente', 'desconocido') NULL,
    ADD COLUMN situacion_legal ENUM('sin_problemas', 'litigio', 'invadido', 'sucesion', 'en_tramite', 'otro') NULL,
    -- Población
    ADD COLUMN familias INT NOT NULL DEFAULT 0,
    ADD COLUMN ninos INT NOT NULL DEFAULT 0,
    ADD COLUMN adultos_mayores INT NOT NULL DEFAULT 0,
    ADD COLUMN personas_discapacidad INT NOT NULL DEFAULT 0,
    -- Control
    ADD COLUMN fecha_inspeccion DATE NULL,
    ADD COLUMN inspector VARCHAR(100) NULL,
    ADD COLUMN observaciones TEXT NULL;
