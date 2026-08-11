<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Servicio de resultados de laboratorio (WinsisLab/PostgreSQL).
 * Replica la lógica de lab-results-api (NestJS): consultas, filtros,
 * exámenes especiales, filtro SaludTotal, validación de pago y
 * elementos parametrizados para el PDF (logo/footer por empresa).
 */
class ResultsService
{
    public const DOM_EXAM_SPECIAL = 'DOM_EXAM_SPECIAL';
    public const COMPANY_SALUD_TOTAL = 'COMPANY_SALUD_TOTAL';

    public function __construct(
        private ManagerRegistry $registry,
        private DomainsService $domainsService,
        private FirmsService $firmsService,
        private UsersService $usersService,
    ) {
    }

    private function beta(): Connection
    {
        return $this->registry->getConnection('beta');
    }

    /**
     * Lista de solicitudes (órdenes) de un paciente por identificación.
     * Se busca por historia (número de documento) sin filtrar por tipo de documento,
     * para que pacientes que cambiaron de tipo (TI→CC, etc.) vean TODO su historial.
     * Devuelve: client_code, request_code, reception_date, exams, url, prevalidated.
     */
    public function findByIdentification(string $identificationNumber, string $identificationType, bool $isPatient): array
    {
        $restricted = '';
        if ($isPatient) {
            $codes = $this->domainsService->activeValues(self::DOM_EXAM_SPECIAL);
            if ($codes) {
                $in = implode(',', array_map(fn (string $c) => "'" . trim($c) . "'", $codes));
                $restricted = "AND (pe.examen NOT IN ($in) OR cl.clte_codigo != 'P') ";
            }
        }

        $sql = "SELECT cl.clte_codigo AS client_code,
                       pa.paciente_cod AS request_code,
                       pa.fecha || ' ' || pa.hora AS reception_date,
                       string_agg(DISTINCT pe.examen, ',') AS exams,
                       pe.archivo AS url,
                       CASE WHEN pe.validado = FALSE AND pe.val_parcial = TRUE THEN TRUE ELSE FALSE END AS prevalidated
                FROM paciente pa
                INNER JOIN paciente_examenes pe ON (pa.historia = pe.historia AND pa.paciente_cod = pe.paciente_cod) AND pa.sede_codigo != '11'
                INNER JOIN clientes cl ON pa.clte_codigo = cl.clte_codigo
                WHERE pa.historia = :historia
                  AND pa.clte_codigo != 'A'
                  AND pe.examen != 'CONSE'
                  AND pe.examen NOT LIKE 'NMX%' AND pe.examen NOT LIKE 'PPDF%'
                  AND (pe.validado = TRUE OR pe.val_parcial = TRUE)
                  $restricted
                GROUP BY cl.clte_codigo, pa.paciente_cod, pa.fecha || ' ' || pa.hora, pe.archivo, pe.validado, pe.val_parcial
                ORDER BY pa.fecha || ' ' || pa.hora DESC";

        $rows = $this->beta()->fetchAllAssociative($sql, [
            'historia' => $identificationNumber,
        ]);

        return $this->filterExams($rows);
    }

    /**
     * Búsqueda de pacientes con filtros (documento, tipo, fechas, nombre, apellido).
     * Si $clientCodes es no nulo, restringe por códigos de cliente del usuario.
     */
    public function findByParameter(
        ?string $clientCodes,
        ?string $identificationNumber,
        ?string $identificationType,
        ?string $name,
        ?string $lastName,
        ?string $startDate,
        ?string $endDate,
    ): array {
        $filters = '';
        $params = [];

        if ($identificationNumber) {
            $filters .= 'AND pa.historia = :historia ';
            $params['historia'] = $identificationNumber;
            // Si se busca por número de documento, se ignora el tipo: el historial
            // puede estar repartido en varios tipos (TI→CC, etc.).
            $identificationType = null;
        }
        if ($identificationType) {
            $filters .= 'AND pa.tipodcto_cod = :tipo ';
            $params['tipo'] = $identificationType;
        }
        if ($startDate && $endDate) {
            $filters .= 'AND pa.fecha BETWEEN :start AND :end ';
            $params['start'] = $startDate;
            $params['end'] = $endDate;
        }
        if ($name) {
            $filters .= 'AND UPPER(pa.nom1) LIKE :name ';
            $params['name'] = '%' . strtoupper($name) . '%';
        }
        if ($lastName) {
            $filters .= 'AND UPPER(pa.ape1) LIKE :last_name ';
            $params['last_name'] = '%' . strtoupper($lastName) . '%';
        }
        if ($clientCodes) {
            $codes = array_filter(array_map('trim', explode(',', $clientCodes)));
            if ($codes) {
                $in = implode(',', array_map(fn (string $c) => "'" . $c . "'", $codes));
                $filters .= "AND cl.clte_codigo IN ($in) ";
            }
        }

        $sql = "SELECT pa.historia AS identification_number,
                       pa.sexo AS sex,
                       (ARRAY_AGG(pa.telefono ORDER BY pa.fecha DESC))[1] AS phone,
                       (ARRAY_AGG(pa.direccion ORDER BY pa.fecha DESC))[1] AS address,
                       (ARRAY_AGG(pa.tipodcto_cod ORDER BY pa.fecha DESC))[1] AS identification_type,
                       (ARRAY_AGG(pa.nom1 ORDER BY pa.fecha DESC))[1] || ' ' || (ARRAY_AGG(pa.ape1 ORDER BY pa.fecha DESC))[1] AS full_name,
                       (ARRAY_AGG(pa.nom1 ORDER BY pa.fecha DESC))[1] AS name,
                       (ARRAY_AGG(pa.ape1 ORDER BY pa.fecha DESC))[1] AS last_name
                FROM paciente pa
                INNER JOIN paciente_examenes pe ON (pa.historia = pe.historia AND pa.paciente_cod = pe.paciente_cod)
                INNER JOIN clientes cl ON pa.clte_codigo = cl.clte_codigo
                WHERE (pe.validado = TRUE OR pe.val_parcial = TRUE)
                  $filters
                GROUP BY pa.historia, pa.sexo
                ORDER BY (ARRAY_AGG(pa.fecha ORDER BY pa.fecha DESC))[1] DESC
                LIMIT 100";

        return $this->beta()->fetchAllAssociative($sql, $params);
    }

    /**
     * Detalle de una solicitud (código de orden). Devuelve el DTO completo
     * con secciones, exámenes, analitos, firma, encabezado/pie y código de barras.
     * Retorna null si no hay resultados.
     */
    public function findByRequest(string $requestCode, bool $isPatient, ?bool $prevalidated = null): ?array
    {
        $restricted = '';
        if ($isPatient) {
            $codes = $this->domainsService->activeValues(self::DOM_EXAM_SPECIAL);
            if ($codes) {
                $in = implode(',', array_map(fn (string $c) => "'" . trim($c) . "'", $codes));
                $restricted = "AND (ex.examen_cod NOT IN ($in) OR cl.clte_codigo != 'P') ";
            }
        }
        $prevalidatedFilter = '';
        if ($prevalidated !== null) {
            $prevalidatedFilter = 'AND (CASE WHEN pe.validado = FALSE AND pe.val_parcial = TRUE THEN TRUE ELSE FALSE END) = ' . ($prevalidated ? 'TRUE' : 'FALSE') . ' ';
        }

        $sql = "SELECT DISTINCT
                  a1.sede_codigo AS headquarters_code,
                  a1.paciente_cod AS request_code,
                  cl.clte_codigo AS client_code,
                  cl.nombre AS client_name,
                  (a1.nom1::TEXT || ' '::TEXT) || a1.ape1::TEXT AS patient_name,
                  a1.edad AS age,
                  a1.sexo AS sex,
                  a1.telefono AS phone,
                  a1.tipodcto_cod AS identification_type,
                  rl.historia AS identification_number,
                  CASE WHEN a1.med_edad = 'A' AND a1.edad = 1 THEN 'año'
                       WHEN a1.med_edad = 'A' AND a1.edad > 1 THEN 'años'
                       WHEN a1.med_edad = 'M' AND a1.edad = 1 THEN 'mes'
                       WHEN a1.med_edad = 'M' AND a1.edad > 1 THEN 'meses'
                       WHEN a1.med_edad = 'D' AND a1.edad = 1 THEN 'día'
                       ELSE 'días'
                  END AS age_unit,
                  ex.tipo_res AS result_type,
                  rl.analito AS analito,
                  rl.resultado AS result,
                  rte.result01 AS ima_result,
                  rl.unidades AS units,
                  ge.nombre AS section_name,
                  ex.examen_cod AS exam_code,
                  ex.nombre AS exam_name,
                  ex.gruexa_cod AS gruexa_code,
                  pe.validado AS validated,
                  m1.nombre AS doctor,
                  rl.analito_cod AS result_code,
                  a1.fecha || ' ' || a1.hora AS reception_date,
                  pe.validado_por AS validated_by,
                  CASE WHEN pe.validado = FALSE AND pe.val_parcial = TRUE THEN TRUE ELSE FALSE END AS prevalidated,
                  pe.fec_val AS validation_date,
                  pe.hora_val AS validation_time,
                  pe.fec_res AS processing_date,
                  pe.hora_resp AS processing_time,
                  rl.minimo AS min,
                  rl.maximo AS max,
                  rl.intermedio AS mid,
                  ex.tipo_exa AS tipo_exa,
                  rl.reg_exa AS reg_exa
                FROM resul_lab rl
                INNER JOIN examenes ex ON ex.examen_cod = rl.examen_cod
                INNER JOIN grupos_examenes ge ON ex.gruexa_cod = ge.gruexa_cod
                INNER JOIN paciente a1 ON a1.historia = rl.historia AND a1.paciente_cod = rl.paciente_cod AND a1.sede_codigo != '11'
                INNER JOIN paciente_examenes pe ON rl.paciente_cod = pe.paciente_cod AND rl.historia = pe.historia AND pe.examen = ex.examen_cod AND pe.reg_exa = rl.reg_exa
                INNER JOIN medicos m1 ON a1.medico_cod = m1.medico_cod
                INNER JOIN clientes cl ON cl.clte_codigo = a1.clte_codigo
                LEFT JOIN LATERAL (
                    SELECT rte2.result01
                    FROM resul_lab_text rte2
                    WHERE rte2.historia = rl.historia AND rte2.paciente_cod = rl.paciente_cod
                    LIMIT 1
                ) rte ON TRUE
                WHERE (pe.validado = TRUE OR pe.val_parcial = TRUE) AND rl.paciente_cod = :request_code
                  AND a1.clte_codigo != 'A' AND ex.examen_cod NOT LIKE 'NMX%' AND ex.examen_cod NOT LIKE 'PPDF%'
                  $restricted
                  $prevalidatedFilter
                GROUP BY a1.sede_codigo, a1.paciente_cod, cl.clte_codigo, cl.nombre,
                  (a1.nom1::TEXT || ' '::TEXT) || a1.ape1::TEXT,
                  a1.edad, a1.sexo, a1.telefono, a1.tipodcto_cod, a1.med_edad,
                  rl.paciente_cod, ex.tipo_res, rl.historia,
                  rl.analito, rl.resultado,
                  rte.result01,
                  rl.unidades, ge.nombre,
                  ex.examen_cod, ex.nombre, ex.gruexa_cod,
                  pe.validado, pe.val_parcial,
                  pe.validado_por,
                  m1.nombre,
                  a1.fecha || ' ' || a1.hora, rl.analito_cod,
                  pe.fec_val, pe.hora_val,
                  pe.fec_res, pe.hora_resp,
                  rl.minimo, rl.maximo, rl.intermedio,
                  ex.tipo_exa, rl.reg_exa
                ORDER BY ge.nombre, ex.examen_cod, rl.reg_exa, rl.analito_cod ASC";

        $rows = $this->beta()->fetchAllAssociative($sql, ['request_code' => $requestCode]);
        if (!$rows) {
            return null;
        }

        $rows = $this->filterResultsIfSaludTotal($rows);
        if (!$rows) {
            return null;
        }

        $result = $rows[0];
        $exams = implode(' ', array_values(array_unique(array_column($rows, 'exam_code'))));
        $doctors = implode(' ', array_values(array_unique(array_column($rows, 'doctor'))));
        $sections = $this->buildSections($rows);
        $parameterized = $this->getParameterizedElement($result['client_code'] ?? '');
        $barcode = ($result['identification_type'] ?? '') . $result['request_code'] . substr(str_replace('-', '', $result['reception_date']), 0, 8);
        $descriptiveTexts = $this->getDescriptiveTexts($rows, $requestCode);

        return [
            'headquarters_code' => $result['headquarters_code'] ?? '',
            'request_code' => $result['request_code'] ?? '',
            'client_code' => $result['client_code'] ?? '',
            'client_name' => $result['client_name'] ?? '',
            'patient_name' => $result['patient_name'] ?? '',
            'age' => $result['age'] ?? '',
            'age_unit' => $result['age_unit'] ?? '',
            'sex' => ($result['sex'] ?? '') === 'M' ? 'Masculino' : 'Femenino',
            'phone' => $result['phone'] ?? '',
            'identification_type' => $result['identification_type'] ?? '',
            'identification_number' => $result['identification_number'] ?? '',
            'validated' => $result['validated'] ?? '',
            'result_code' => $result['result_code'] ?? '',
            'reception_date' => $result['reception_date'] ?? '',
            'today' => date('Y-m-d h:i:s'),
            'exams' => $exams,
            'doctors' => $doctors,
            'sections' => $sections,
            'header' => $parameterized['header'],
            'footer' => $parameterized['footer'],
            'cell' => $parameterized['cell'],
            'font_color' => $parameterized['font_color'],
            'barcode' => $barcode,
            'prevalidated' => (bool) ($result['prevalidated'] ?? false),
            'descriptive_texts' => $descriptiveTexts,
        ];
    }

    /**
     * Textos descriptivos para exámenes tipo D (resul_lab_text), replica V2026.
     */
    private function getDescriptiveTexts(array $results, string $requestCode): array
    {
        $hasTypeD = false;
        foreach ($results as $r) {
            if (($r['result_type'] ?? '') !== '' && strtoupper((string) $r['result_type']) === 'D') {
                $hasTypeD = true;
                break;
            }
        }
        if (!$hasTypeD) {
            return [];
        }
        $rows = $this->beta()->fetchAllAssociative(
            "SELECT ex.nombre AS exam_name, rlt.result01 AS descriptive_text
             FROM resul_lab_text rlt
             INNER JOIN examenes ex ON ex.examen_cod = rlt.pac_examen
             WHERE rlt.paciente_cod = :request_code
               AND rlt.result01 IS NOT NULL AND rlt.result01 != ''",
            ['request_code' => $requestCode]
        );
        return array_map(fn ($r) => [
            'exam_name' => strtoupper((string) ($r['exam_name'] ?? '')),
            'descriptive_text' => (string) ($r['descriptive_text'] ?? ''),
        ], $rows);
    }

    /**
     * Busca la URL del archivo publicado (pe.archivo) de una solicitud y códigos de examen.
     * Replica findPacienteExamenes de V2026 (usado para la descarga por FTP).
     */
    public function findPacienteExamenes(string $requestCode, string $examCodes): ?string
    {
        $examArray = array_values(array_filter(array_map('trim', explode(',', $examCodes)), 'strlen'));
        if (!$examArray) {
            return null;
        }
        // Solo se aceptan códigos alfanuméricos (evita inyección SQL desde el parámetro url).
        $examArray = array_values(array_filter($examArray, fn (string $e) => (bool) preg_match('/^[A-Za-z0-9_\-\.]+$/', $e)));
        if (!$examArray) {
            return null;
        }
        $in = implode(',', array_map(fn (string $e) => "'" . $e . "'", $examArray));
        $row = $this->beta()->fetchAssociative(
            "SELECT archivo AS url
             FROM paciente_examenes
             WHERE paciente_cod = :request_code
               AND examen IN ($in)
             ORDER BY fecha DESC
             LIMIT 1",
            ['request_code' => $requestCode]
        );
        $url = (string) ($row['url'] ?? '');
        return $url === '' ? null : $url;
    }

    /**
     * Estadísticas del dashboard según el tipo de usuario.
     * - company: solicitudes (últimos 30 días), pacientes y exámenes de sus códigos de empresa.
     * - person: solicitudes y exámenes del paciente.
     */
    public function dashboardStats(string $type, string $identification, string $identificationType, string $clientCodes): array
    {
        if ($type === 'company') {
            $codes = array_values(array_filter(array_map('trim', explode(',', $clientCodes)), 'strlen'));
            if (!$codes) {
                return ['requests' => 0, 'patients' => 0, 'exams' => 0];
            }
            $in = implode(',', array_map(fn (string $c) => "'" . $c . "'", $codes));
            $base = "FROM paciente pa
                     INNER JOIN paciente_examenes pe ON pa.historia = pe.historia AND pa.paciente_cod = pe.paciente_cod
                     WHERE pa.clte_codigo IN ($in) AND (pe.validado = TRUE OR pe.val_parcial = TRUE)";
            $requests = (int) $this->beta()->fetchOne("SELECT COUNT(DISTINCT pa.paciente_cod) $base AND pa.fecha >= CURRENT_DATE - INTERVAL '30 days'");
            $patients = (int) $this->beta()->fetchOne("SELECT COUNT(DISTINCT pa.historia) $base");
            $exams = (int) $this->beta()->fetchOne("SELECT COUNT(DISTINCT pe.examen) $base");
            return ['requests' => $requests, 'patients' => $patients, 'exams' => $exams];
        }

        $base = "FROM paciente pa
                 INNER JOIN paciente_examenes pe ON pa.historia = pe.historia AND pa.paciente_cod = pe.paciente_cod
                 WHERE pa.historia = :h AND pa.tipodcto_cod = :t AND (pe.validado = TRUE OR pe.val_parcial = TRUE)";
        $params = ['h' => $identification, 't' => $identificationType];
        $requests = (int) $this->beta()->fetchOne("SELECT COUNT(DISTINCT pa.paciente_cod) $base", $params);
        $exams = (int) $this->beta()->fetchOne("SELECT COUNT(DISTINCT pe.examen) $base", $params);
        return ['requests' => $requests, 'exams' => $exams];
    }

    /**
     * Valida que la solicitud esté al día con el pago (vr_total o copago).
     */
    public function isPaid(string $requestCode): bool
    {        $sql = "SELECT * FROM (SELECT
                    paciente_cod,
                    vr_total,
                    abono1,
                    abono2,
                    por_copago,
                    vr_copago,
                    desto,
                    destop,
                    (abono1 + abono2) AS abonos,
                    (vr_total - desto - destop) AS valor_real,
                    (abono1 + abono2 + desto + destop) >= vr_total AS esvalidoporvalor,
                    vr_copago = por_copago AS esvalidoporcopago
                 FROM paciente) AS val
                WHERE val.paciente_cod = :request_code
                  AND (val.esvalidoporcopago = TRUE OR val.esvalidoporvalor = TRUE)";
        $row = $this->beta()->fetchAssociative($sql, ['request_code' => $requestCode]);
        return (bool) $row;
    }

    /**
     * Construye las secciones (grupos) con exámenes y analitos, más la firma del validante.
     * El último validador se determina por fecha/hora de validación (replica V2026).
     */
    private function buildSections(array $rows): array
    {
        $sections = [];
        foreach (array_values(array_unique(array_column($rows, 'section_name'))) as $sectionName) {
            $bySection = array_values(array_filter($rows, fn ($r) => $r['section_name'] === $sectionName));
            $examCodes = array_values(array_unique(array_column($bySection, 'exam_code')));

            $lastValidator = null;
            foreach ($bySection as $r) {
                if (empty($r['validation_date'])) {
                    continue;
                }
                $key = (string) $r['validation_date'] . ' ' . (string) ($r['validation_time'] ?? '00:00:00');
                if ($lastValidator === null || $key > $lastValidator['_key']) {
                    $lastValidator = ['_key' => $key, 'validated_by' => $r['validated_by'] ?? ''];
                }
            }
            if ($lastValidator === null && $bySection) {
                $lastValidator = ['_key' => '', 'validated_by' => $bySection[0]['validated_by'] ?? ''];
            }
            $validatedBy = $lastValidator['validated_by'] ?? '';

            $exams = [];
            foreach ($examCodes as $examCode) {
                $byExam = array_values(array_filter($bySection, fn ($r) => $r['exam_code'] === $examCode));
                $details = [];
                foreach ($byExam as $be) {
                    $units = (string) ($be['units'] ?? '');
                    $details[] = [
                        'result_type' => $be['result_type'] ?? '',
                        'analito' => $be['analito'] ?? '',
                        'result' => ($be['result'] ?? '') === '_' ? '' : ($be['result'] ?? ''),
                        'ima_result' => $be['ima_result'] ?? '',
                        'units' => ($units !== 'F' && $units !== 'Q' && $units !== 'V') ? $units : '',
                        'gruexa_code' => $be['gruexa_code'] ?? '',
                        'min' => $be['min'] ?? '',
                        'max' => $be['max'] ?? '',
                        'mid' => $be['mid'] ?? '',
                    ];
                }

                $latest = null;
                foreach ($byExam as $r) {
                    if (empty($r['validation_date'])) {
                        continue;
                    }
                    $key = (string) $r['validation_date'] . ' ' . (string) ($r['validation_time'] ?? '00:00:00');
                    if ($latest === null || $key > $latest['_key']) {
                        $latest = ['_key' => $key, 'row' => $r];
                    }
                }
                $latestRow = $latest ? $latest['row'] : $byExam[0];

                $exams[] = [
                    'exam_code' => $examCode,
                    'exam_name' => $byExam[0]['exam_name'] ?? '',
                    'validation_date' => $this->dateToString($latestRow['validation_date'] ?? ''),
                    'validation_time' => (string) ($latestRow['validation_time'] ?? ''),
                    'processing_date' => $this->dateToString($latestRow['processing_date'] ?? ''),
                    'processing_time' => (string) ($latestRow['processing_time'] ?? ''),
                    'details' => $details,
                ];
            }

            $sections[] = [
                'name' => $sectionName,
                'validated_by' => $validatedBy,
                'doctor' => $bySection[0]['doctor'] ?? '',
                'exams' => $exams,
                'signature' => $this->getSignatureBase64($validatedBy),
            ];
        }
        return $sections;
    }

    private function dateToString(mixed $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        return (string) substr((string) $date, 0, 10);
    }

    /**
     * Firma del médico validante (imagen base64) o placeholder.
     */
    private function getSignatureBase64(string $validatedBy): string
    {
        $firm = $this->firmsService->findByCode($validatedBy);
        if ($firm) {
            $path = $this->firmsService->resolvePath($firm['url']);
            if ($path) {
                return $this->fileToBase64($path);
            }
        }
        return $this->fileToBase64($this->firmsService->resolvePath('images/none.jpg'));
    }

    /**
     * Encabezado/pie/celda según el logo configurado del cliente (empresa).
     */
    private function getParameterizedElement(string $clientCode): array
    {
        $cell = '#3276b1;';
        $fontColor = 'black';
        $header = $this->fileToBase64($this->publicPath('static/images/header.jpg'));
        $footer = $this->fileToBase64($this->publicPath('static/images/footer.jpg'));

        $user = $this->usersService->findUserByClientCode($clientCode);
        if ($user && $clientCode) {
            $logo = $user->getLogoOptions() ?: '';
            if ($logo === 'logo_empresa') {
                $cell = '#FFFFFF;';
                $headerPath = $this->usersService->resolveUploadPath($user->getUrlimg());
                $footerPath = $this->usersService->resolveUploadPath($user->getFooter());
                if ($headerPath) {
                    $header = $this->fileToBase64($headerPath);
                }
                if ($footerPath) {
                    $footer = $this->fileToBase64($footerPath);
                }
            } elseif ($logo === 'sin_logo') {
                $header = $this->fileToBase64($this->publicPath('static/images/none.jpg'));
                $footer = $this->fileToBase64($this->publicPath('static/images/none.jpg'));
            }
        }

        return ['cell' => $cell, 'font_color' => $fontColor, 'header' => $header, 'footer' => $footer];
    }

    /**
     * Filtro SaludTotal aplicado sobre la lista de solicitudes (exámenes por solicitud).
     */
    private function filterExams(array $userExams): array
    {
        $saludTotal = $this->getSaludTotalDomain();
        if (!$saludTotal) {
            return $userExams;
        }
        $saludTotalCodes = array_map('trim', explode(',', $saludTotal['valor']));
        $saludTotalExams = array_map('trim', explode(',', $saludTotal['titulo']));

        $final = [];
        foreach ($userExams as $userExam) {
            $isSaludTotal = in_array($userExam['client_code'], $saludTotalCodes, true);
            if (($userExam['client_code'] ?? '') !== 'P' && $isSaludTotal) {
                $examsArray = array_filter(explode(',', $userExam['exams']));
                $finalExams = array_values(array_filter($examsArray, fn ($e) => in_array(trim($e), $saludTotalExams, true)));
                $userExam['exams'] = implode(',', $finalExams);
                $final[] = $userExam;
            } else {
                $final[] = $userExam;
            }
        }
        return $final;
    }

    /**
     * Filtro SaludTotal sobre el detalle (analitos).
     */
    private function filterResultsIfSaludTotal(array $results): array
    {
        $hasClientP = in_array('P', array_column($results, 'client_code'), true);
        if ($hasClientP) {
            return $results;
        }
        $saludTotal = $this->getSaludTotalDomain();
        if (!$saludTotal) {
            return $results;
        }
        $saludTotalCodes = array_map('trim', explode(',', $saludTotal['valor']));
        $saludTotalExams = array_map('trim', explode(',', $saludTotal['titulo']));

        $isSaludTotal = count(array_intersect(array_column($results, 'client_code'), $saludTotalCodes)) > 0;
        if ($isSaludTotal) {
            return array_values(array_filter($results, fn ($r) => !in_array($r['exam_code'], $saludTotalExams, true)));
        }
        return $results;
    }

    private function getSaludTotalDomain(): ?array
    {
        return $this->domainsService->findOneActiveByName(self::COMPANY_SALUD_TOTAL);
    }

    private function publicPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/public/' . ltrim($relative, '/');
    }

    private function fileToBase64(string $path): string
    {
        if (!is_file($path)) {
            $path = $this->publicPath('static/images/none.jpg');
        }
        $mime = (string) mime_content_type($path);
        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}
