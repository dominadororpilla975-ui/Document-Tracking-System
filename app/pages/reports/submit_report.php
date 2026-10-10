<?php
if (!defined('APP_ROUTE_ENTRY')) {
    http_response_code(404);
    exit('Not Found');
}

require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/includes/tracking.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/notifications.php';
require_once APP_ROOT . '/includes/mailer.php';
require_once APP_ROOT . '/includes/upload_security.php';

requireLogin();
$user = currentUser();
requirePermission('create_reports', $user);
$isClientSubmission = isClientUser($user);
$isDepartmentSubmission = in_array(strtolower((string) ($user['role'] ?? '')), ['department_staff', 'staff'], true);
$hideAttachment = $isClientSubmission || isDepartmentAdmin($user) || strtolower((string) ($user['role'] ?? '')) === 'department';

$error = '';
$success = '';
$priority = 'Medium';
$processingTimeAcknowledged = false;
$postMaxSize = trim((string) ini_get('post_max_size'));
$postMaxBytes = 0;
if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?B?$/i', $postMaxSize, $postMaxMatch)) {
    $postMaxBytes = (int) ((float) $postMaxMatch[1] * match (strtoupper($postMaxMatch[2] ?? '')) {
        'K' => 1024,
        'M' => 1024 ** 2,
        'G' => 1024 ** 3,
        default => 1,
    });
}
$requestContentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postExceedsLimit = $postMaxBytes > 0 && $requestContentLength > $postMaxBytes;
$maxUploadFileBytes = 25 * 1024 * 1024;

function submissionFileMimeType(string $filePath, string $fileName): ?string
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return null;
        }
        $header = fread($handle, 1024);
        fclose($handle);

        return is_string($header) && preg_match('/%PDF-\d\.\d/', $header) === 1
            ? 'application/pdf'
            : null;
    }

    $mimeType = strtolower((new finfo(FILEINFO_MIME_TYPE))->file($filePath));
    $allowedMimeTypes = [
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/cdfv2'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
        ],
    ];

    return in_array($mimeType, $allowedMimeTypes[$extension] ?? [], true) ? $mimeType : null;
}

function validateSubmissionText(string $value, string $label, bool $required = false): ?string
{
    $value = trim($value);
    if ($value === '') {
        return $required ? "{$label} is required." : null;
    }
    if (mb_strlen($value) < 3 || mb_strlen($value) > 255) {
        return "{$label} must be between 3 and 255 characters.";
    }
    if (!preg_match('/\p{L}/u', $value)) {
        return "{$label} must contain valid letters.";
    }

    $lettersOnly = preg_replace('/[^\p{L}]/u', '', $value) ?? '';
    $lowerLetters = mb_strtolower($lettersOnly);
    $hasVowel = preg_match('/[aeiou]/i', $lettersOnly) === 1;
    $hasCommonWord = preg_match('/\b(the|for|and|request|document|permit|application|report|office|service|assistance|certificate|clearance|endorsement)\b/i', $value) === 1;
    $hasAcronym = mb_strlen($lettersOnly) <= 5 && preg_match('/^[A-Z]+$/', $lettersOnly) === 1;
    $hasLongConsonantRun = preg_match('/[bcdfghjklmnpqrstvwxyz]{5,}/i', $lowerLetters) === 1;
    $hasRepeatedCharacters = preg_match('/(.)\1{3,}/u', $lowerLetters) === 1;
    $isKeyboardPattern = preg_match('/(qwerty|asdf|zxcv|poiuy|lkjh|mnbv)/i', $lowerLetters) === 1;
    if (!$hasVowel && !$hasCommonWord && !$hasAcronym || $hasLongConsonantRun || $hasRepeatedCharacters || $isKeyboardPattern) {
        return "{$label} must be a meaningful name or description.";
    }
    return null;
}

// Departments are database-driven; a department does not disappear just because staff has not been assigned yet.
$departments = $pdo->query("SELECT d.* FROM departments d WHERE d.status = 'active' AND d.deleted_at IS NULL ORDER BY d.name ASC")->fetchAll();
$servicesStmt = $pdo->query("SELECT id, department_id, service_name, description, detail_rows, sla_days, priority FROM department_services WHERE status = 'Active' AND deleted_at IS NULL ORDER BY department_id ASC, service_name ASC");
$activeServices = $servicesStmt->fetchAll();
$servicesByDepartment = [];
foreach ($activeServices as $service) {
    $service['detail_rows'] = json_decode((string) ($service['detail_rows'] ?? ''), true);
    if (!is_array($service['detail_rows'])) {
        $service['detail_rows'] = [];
    }
    $servicesByDepartment[(int) $service['department_id']][] = $service;
}
$serviceIds = array_map(static fn (array $service): int => (int) $service['id'], $activeServices);
$serviceDocumentsByService = [];
if ($serviceIds) {
    $serviceIdsSql = implode(',', $serviceIds);
    $serviceDocuments = $pdo->query(
        "SELECT id, service_id, original_name FROM service_documents WHERE service_id IN ({$serviceIdsSql}) ORDER BY created_at DESC, id DESC"
    )->fetchAll();
    foreach ($serviceDocuments as $serviceDocument) {
        $serviceDocumentsByService[(int) $serviceDocument['service_id']][] = [
            'id' => (int) $serviceDocument['id'],
            'original_name' => (string) $serviceDocument['original_name'],
        ];
    }
}
foreach ($servicesByDepartment as &$departmentServices) {
    foreach ($departmentServices as &$departmentService) {
        $departmentService['documents'] = $serviceDocumentsByService[(int) $departmentService['id']] ?? [];
    }
    unset($departmentService);
}
unset($departmentServices);
$approvedClients = $pdo->query("SELECT id, name, email FROM users WHERE role = 'client' AND account_status = 'active' ORDER BY name ASC, email ASC")->fetchAll();
$approvedClientMap = [];
foreach ($approvedClients as $approvedClient) {
    $approvedClientMap[strtolower((string)$approvedClient['email'])] = (string)$approvedClient['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$postExceedsLimit) {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $additionalInformation = trim($_POST['additional_information'] ?? '');
    $activityDetailsAnswers = $_POST['activity_detail_answer'] ?? [];
    if (!is_array($activityDetailsAnswers)) {
        $activityDetailsAnswers = [];
    }
    $activityDetailsAnswers = array_map(
        static fn ($answer): string => trim((string) $answer),
        $activityDetailsAnswers
    );
    $activityDetailFiles = $_FILES['activity_detail_file'] ?? [];
    $processingTimeAcknowledged = $isClientSubmission
        && ($_POST['processing_time_acknowledged'] ?? '') === '1';
    $submittedPriority = $_POST['priority'] ?? 'Medium';
    $priority = is_string($submittedPriority) ? trim($submittedPriority) : '';
    if (!in_array($priority, ['Low', 'Medium', 'High', 'Urgent'], true)) {
        $error = 'Select a valid priority level.';
    }
    $submissionMode = $isClientSubmission ? 'online' : 'walkin';
    $submissionSource = $isClientSubmission ? 'client' : 'department';
    $clientName = trim($_POST['client_name'] ?? '');
    $clientEmail = trim($_POST['client_email'] ?? '');
    if ($isClientSubmission) {
        $clientName = (string) ($user['name'] ?? '');
        $clientEmail = (string) ($user['email'] ?? '');
    }
    
    $originatingDeptId = $isDepartmentSubmission
        ? (int) ($user['department_id'] ?? 0)
        : ($isClientSubmission
        ? (int) ($_POST['department_id'] ?? 0)
        : (int) ($_POST['originating_department_id'] ?? 0));
    $serviceId = $isClientSubmission ? (int) ($_POST['service_id'] ?? 0) : 0;
    $selectedDepartments = ($isClientSubmission || $isDepartmentSubmission) ? [] : ($_POST['department_id'] ?? []);
    if (!is_array($selectedDepartments)) {
        $selectedDepartments = [$selectedDepartments];
    }
    $receivingDeptIds = [];
    foreach ($selectedDepartments as $deptId) {
        $deptId = (int)$deptId;
        if ($deptId > 0) {
            $receivingDeptIds[] = $deptId;
        }
    }
    $receivingDeptIds = array_values($receivingDeptIds);

    $selectedService = null;
    if ($originatingDeptId > 0 && $serviceId > 0) {
        $serviceStmt = $pdo->prepare("SELECT id, service_name, detail_rows, sla_days FROM department_services WHERE id = :id AND department_id = :department_id AND status = 'Active' AND deleted_at IS NULL LIMIT 1");
        $serviceStmt->execute(['id' => $serviceId, 'department_id' => $originatingDeptId]);
        $selectedService = $serviceStmt->fetch();
        if ($selectedService) {
            $title = trim((string) $selectedService['service_name']);
        }
    }

    $attachment = $hideAttachment ? null : ($_FILES['attachment'] ?? null);
    $hasSubmissionUpload = hasUploadAttempt($attachment) || hasUploadAttempt($activityDetailFiles);
    $attachmentData = null;
    if ($attachment && ($attachment['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $attachmentError = (int) ($attachment['error'] ?? UPLOAD_ERR_OK);
        if ($attachmentError === UPLOAD_ERR_INI_SIZE || $attachmentError === UPLOAD_ERR_FORM_SIZE) {
            $error = 'This file is larger than the PHP upload_max_filesize setting. Restart the local PHP server with upload_max_filesize=25M.';
        } elseif ($attachmentError !== UPLOAD_ERR_OK || (int) ($attachment['size'] ?? 0) > $maxUploadFileBytes) {
            $error = 'Each file must be 25 MB or smaller.';
        } else {
            $mimeType = submissionFileMimeType((string) $attachment['tmp_name'], (string) $attachment['name']);
            if ($mimeType === null) {
                $error = 'Upload a valid PDF, Word (.doc/.docx), or Excel (.xls/.xlsx) file. Images, videos, and cloud links are not allowed.';
            } else {
                $attachmentData = [
                    'original_name' => basename((string) $attachment['name']),
                    'mime_type' => $mimeType,
                    'file_size' => (int) $attachment['size'],
                    'tmp_name' => $attachment['tmp_name'],
                ];
            }
        }
    }

    if (empty($error) && $selectedService) {
        $detailRowsForUploadValidation = json_decode((string) ($selectedService['detail_rows'] ?? ''), true);
        foreach (is_array($detailRowsForUploadValidation) ? $detailRowsForUploadValidation : [] as $detailIndex => $detailRow) {
            if (($detailRow['type'] ?? 'text') !== 'file') {
                continue;
            }

            $fileError = (int) ($activityDetailFiles['error'][$detailIndex] ?? UPLOAD_ERR_NO_FILE);
            if ($fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($fileError === UPLOAD_ERR_INI_SIZE || $fileError === UPLOAD_ERR_FORM_SIZE) {
                $error = 'The file for ' . (string) ($detailRow['label'] ?? 'Additional detail') . ' is larger than the PHP upload_max_filesize setting. Restart the local PHP server with upload_max_filesize=25M.';
                break;
            }
            if ($fileError !== UPLOAD_ERR_OK) {
                $error = 'Unable to upload the required file: ' . (string) ($detailRow['label'] ?? 'Additional detail') . '.';
                break;
            }

            $fileName = (string) ($activityDetailFiles['name'][$detailIndex] ?? '');
            $temporaryPath = (string) ($activityDetailFiles['tmp_name'][$detailIndex] ?? '');
            $fileSize = (int) ($activityDetailFiles['size'][$detailIndex] ?? 0);
            $label = (string) ($detailRow['label'] ?? 'Additional detail');
            if ($fileSize > $maxUploadFileBytes) {
                $error = 'The file for ' . $label . ' must be 25 MB or smaller.';
                break;
            }

            $mimeType = submissionFileMimeType($temporaryPath, $fileName);
            if ($mimeType === null) {
                $error = $label . ': Upload a valid PDF, Word (.doc/.docx), or Excel (.xls/.xlsx) file. Images, videos, and cloud links are not allowed.';
                break;
            }
        }
    }

    if (empty($error) && ($validationError = validateSubmissionText($title, 'Document Title', !$isClientSubmission))) {
        $error = $validationError;
    } elseif ($isClientSubmission && !$processingTimeAcknowledged) {
        $error = 'Please confirm that you understand the normal document review and processing time before submitting.';
    } elseif (empty($error) && ($validationError = validateSubmissionText($category, 'Document Type / Category'))) {
        $error = $validationError;
    } elseif (empty($error) && ($validationError = validateSubmissionText($purpose, 'Purpose'))) {
        $error = $validationError;
    } elseif (empty($error) && ($validationError = validateSubmissionText($additionalInformation, 'Additional Information'))) {
        $error = $validationError;
    } elseif (empty($error) && (empty($clientName) || empty($clientEmail))) {
        $error = "Client Name and Client Email are required.";
    } elseif (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid Client Email address.";
    } elseif ($originatingDeptId <= 0) {
        $error = "Select a First Department / Origin Department.";
    } elseif ($isClientSubmission && $serviceId <= 0) {
        $error = "Select the service or activity requested from the first department.";
    } elseif (!$isClientSubmission && !$isDepartmentSubmission && empty($receivingDeptIds)) {
        $error = "Select at least one succeeding department.";
    } elseif (!$isClientSubmission && !$isDepartmentSubmission && in_array($originatingDeptId, $receivingDeptIds, true)) {
        $error = "The First Department cannot be selected again in the workflow.";
    } elseif (!$isClientSubmission && !$isDepartmentSubmission && count($receivingDeptIds) !== count(array_unique($receivingDeptIds))) {
        $error = "Duplicate departments are not allowed in the workflow.";
    } elseif (empty($error)) {
        $retryAfter = $hasSubmissionUpload ? enforceUploadCooldown($pdo, (int) $user['id']) : 0;
        if ($retryAfter > 0) {
            $error = uploadCooldownMessage($retryAfter);
        } else {
        $pdo->beginTransaction();
        try {
            if ($serviceId > 0 && !$selectedService) {
                $serviceStmt = $pdo->prepare("SELECT id, service_name, detail_rows, sla_days FROM department_services WHERE id = :id AND department_id = :department_id AND status = 'Active' AND deleted_at IS NULL LIMIT 1");
                $serviceStmt->execute(['id' => $serviceId, 'department_id' => $originatingDeptId]);
                $selectedService = $serviceStmt->fetch();
            }
            if ($serviceId > 0 && !$selectedService) {
                throw new Exception('The selected service is not active or does not belong to the first department.');
            }
            $serviceDetailRows = json_decode((string) ($selectedService['detail_rows'] ?? ''), true);
            $answeredDetails = [];
            $requirementFiles = [];
            if (is_array($serviceDetailRows)) {
                foreach ($serviceDetailRows as $detailIndex => $detailRow) {
                    $label = (string) ($detailRow['label'] ?? 'Additional detail');
                    $type = (($detailRow['type'] ?? 'text') === 'file') ? 'file' : 'text';
                    $answer = '';
                    if ($type === 'file') {
                        $fileError = (int) ($activityDetailFiles['error'][$detailIndex] ?? UPLOAD_ERR_NO_FILE);
                        if ($fileError !== UPLOAD_ERR_NO_FILE && $fileError !== UPLOAD_ERR_OK) {
                            throw new Exception('Unable to upload the required file: ' . $label . '.');
                        }
                        if ($fileError === UPLOAD_ERR_OK) {
                            $file = [
                                'name' => $activityDetailFiles['name'][$detailIndex] ?? '',
                                'type' => $activityDetailFiles['type'][$detailIndex] ?? '',
                                'tmp_name' => $activityDetailFiles['tmp_name'][$detailIndex] ?? '',
                                'size' => $activityDetailFiles['size'][$detailIndex] ?? 0,
                            ];
                            if ((int) $file['size'] > $maxUploadFileBytes) {
                                throw new Exception('The file for ' . $label . ' must be 25 MB or smaller.');
                            }
                            $mimeType = submissionFileMimeType((string) $file['tmp_name'], (string) $file['name']);
                            if ($mimeType === null) {
                                throw new Exception($label . ': Upload a valid PDF, Word (.doc/.docx), or Excel (.xls/.xlsx) file. Images, videos, and cloud links are not allowed.');
                            }
                            $file['mime_type'] = $mimeType;
                            $file['original_name'] = basename((string) $file['name']);
                            $requirementFiles[] = ['label' => $label, 'file' => $file];
                            $answer = $file['original_name'];
                        }
                    } else {
                        $answer = trim((string) ($activityDetailsAnswers[$detailIndex] ?? ''));
                    }
                    if (!empty($detailRow['required']) && $answer === '') {
                        throw new Exception('Please complete the required activity detail: ' . $label . '.');
                    }
                    $answeredDetails[] = [
                        'label' => $label,
                        'value' => $answer,
                        'type' => $type,
                    ];
                }
            }

            $configuredWorkflowDepartments = [];
            if ($serviceId > 0) {
                $configuredWorkflowStmt = $pdo->prepare(
                    "SELECT department_id FROM service_workflow_steps
                     WHERE service_id = :service_id AND status = 'Active'
                     ORDER BY step_number ASC"
                );
                $configuredWorkflowStmt->execute(['service_id' => $serviceId]);
                $configuredWorkflowDepartments = array_map('intval', $configuredWorkflowStmt->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($isClientSubmission || $isDepartmentSubmission) {
                $workflowDeptIds = $isDepartmentSubmission
                    ? [$originatingDeptId]
                    : ($configuredWorkflowDepartments ?: [$originatingDeptId]);
                if ((int) $workflowDeptIds[0] !== $originatingDeptId) {
                    throw new Exception('The selected department is not the first department configured for this service.');
                }
            } else {
                $workflowDeptIds = array_merge([$originatingDeptId], $receivingDeptIds);
            }
            // Clients are always linked to the authenticated account; staff submissions may select an active client.
            if ($isClientSubmission) {
                $uStmt = $pdo->prepare("SELECT id, name, password, temp_password, google_id, account_status, email FROM users WHERE id = :id LIMIT 1");
                $uStmt->execute(['id' => (int) $user['id']]);
            } else {
                $uStmt = $pdo->prepare("SELECT id, name, password, temp_password, google_id, account_status, email FROM users WHERE LOWER(email) = :email LIMIT 1");
                $uStmt->execute(['email' => strtolower($clientEmail)]);
            }
            $existingUser = $uStmt->fetch();

            if (!$existingUser) {
                throw new Exception('Client email must belong to an existing Google account. Fake or unregistered emails are not allowed.');
            }

            if (!$isClientSubmission && empty($existingUser['google_id'])) {
                throw new Exception('Only Google-linked client accounts can be used when creating a document.');
            }

            if (($existingUser['account_status'] ?? 'active') !== 'active') {
                throw new Exception('This Google client account is not active and cannot be used for document creation.');
            }

            $clientId = (int) $existingUser['id'];
            $clientName = (string) $existingUser['name'];
            $clientEmail = (string) $existingUser['email'];
            $tempPassword = $existingUser['temp_password'] ?? null;
            $isNewClient = false;

            if (empty($tempPassword)) {
                $tempPassword = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $hashedTemp = password_hash($tempPassword, PASSWORD_BCRYPT);
                $resetStmt = $pdo->prepare("UPDATE users SET password = :password, temp_password = :temp_password WHERE id = :id");
                $resetStmt->execute([
                    'password' => $hashedTemp,
                    'temp_password' => $tempPassword,
                    'id' => $clientId
                ]);
            }

            $senderDeptId = $user['department_id'] ?: null;
            $status = $isClientSubmission ? 'Pending' : 'Assigned';
            $firstReceivingDeptId = $originatingDeptId;
            $totalWorkflowSteps = count($workflowDeptIds) + 1;
            $deptNameMap = [];
            foreach ($departments as $dept) {
                $deptNameMap[$dept['id']] = $dept['name'];
            }

            $deadlineAt = !empty($selectedService['sla_days']) ? date('Y-m-d H:i:s', strtotime('+' . (int) $selectedService['sla_days'] . ' days')) : null;
            $stmt = $pdo->prepare("INSERT INTO reports (title, description, service_id, service_name, category, purpose, additional_information, activity_details_answers, deadline_at, priority, client_id, client_name, client_email, submission_mode, submission_source, department_id, sender_department_id, originating_department_id, assigned_department_id, status, current_workflow_step, total_workflow_steps, department_private, submitted_by_user_id)
                                  VALUES (:title, :description, :service_id, :service_name, :category, :purpose, :additional_information, :activity_details_answers, :deadline_at, :priority, :client_id, :client_name, :client_email, :submission_mode, :submission_source, :dept_id, :sender_dept, :originating_dept, :assigned_dept, :status, 1, :total_steps, :department_private, :submitted_by_user_id)");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'service_id' => $serviceId > 0 ? $serviceId : null,
                'service_name' => $selectedService['service_name'] ?? null,
                'category' => $category !== '' ? $category : null,
                'purpose' => $purpose !== '' ? $purpose : null,
                'additional_information' => $additionalInformation !== '' ? $additionalInformation : null,
                'activity_details_answers' => json_encode($answeredDetails, JSON_UNESCAPED_UNICODE),
                'deadline_at' => $deadlineAt,
                'priority' => $priority,
                'client_id' => $clientId,
                'client_name' => $clientName,
                'client_email' => $clientEmail,
                'submission_mode' => $submissionMode,
                'submission_source' => $submissionSource,
                'dept_id' => $firstReceivingDeptId,
                'sender_dept' => $senderDeptId,
                'originating_dept' => $originatingDeptId,
                'assigned_dept' => $firstReceivingDeptId,
                'status' => $status,
                'total_steps' => $totalWorkflowSteps,
                'department_private' => $isDepartmentSubmission ? 1 : 0,
                'submitted_by_user_id' => (int) $user['id']
            ]);
            $reportId = $pdo->lastInsertId();
            $trackingNo = generateTrackingNumber($pdo);

            // Update tracking number column
            $upd = $pdo->prepare("UPDATE reports SET tracking_number = :tracking_number WHERE id = :id");
            $upd->execute(['tracking_number' => $trackingNo, 'id' => $reportId]);

            if ($attachmentData) {
                $storedName = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($attachmentData['original_name'], PATHINFO_EXTENSION));
                $uploadDirectory = APP_ROOT . '/uploads';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
                    throw new Exception('Unable to prepare secure attachment storage.');
                }
                if (!move_uploaded_file($attachmentData['tmp_name'], $uploadDirectory . '/' . $storedName)) {
                    throw new Exception('Unable to save the document attachment.');
                }
                $attachmentStmt = $pdo->prepare('INSERT INTO attachments (report_id, original_name, stored_name, mime_type, file_size, version_number, uploaded_by) VALUES (:report_id, :original_name, :stored_name, :mime_type, :file_size, 1, :uploaded_by)');
                $attachmentStmt->execute([
                    'report_id' => $reportId,
                    'original_name' => $attachmentData['original_name'],
                    'stored_name' => $storedName,
                    'mime_type' => $attachmentData['mime_type'],
                    'file_size' => $attachmentData['file_size'],
                    'uploaded_by' => $user['id'],
                ]);
            }
            foreach ($requirementFiles as $requirement) {
                $file = $requirement['file'];
                $storedName = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                $uploadDirectory = APP_ROOT . '/uploads';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true)) {
                    throw new Exception('Unable to prepare secure requirement storage.');
                }
                if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $storedName)) {
                    throw new Exception('Unable to save the requirement file.');
                }
                $requirementStmt = $pdo->prepare('INSERT INTO attachments (report_id, original_name, stored_name, mime_type, file_size, version_number, requirement_name, uploaded_by) VALUES (:report_id, :original_name, :stored_name, :mime_type, :file_size, 1, :requirement_name, :uploaded_by)');
                $requirementStmt->execute([
                    'report_id' => $reportId,
                    'original_name' => $file['original_name'],
                    'stored_name' => $storedName,
                    'mime_type' => $file['mime_type'],
                    'file_size' => (int) $file['size'],
                    'requirement_name' => $requirement['label'],
                    'uploaded_by' => $user['id'],
                ]);
            }

            $stepNumber = 1;
            foreach ($workflowDeptIds as $deptId) {
                $routeStatus = $stepNumber === 1 ? 'Awaiting Receiving' : 'Pending';
                $approverNotes = $stepNumber === 1 ? 'Document created and awaiting receiving acknowledgement' : 'Pending workflow step';
                $rStmt = $pdo->prepare("INSERT INTO report_workflow_routes (report_id, step_number, step_order, department_id, destination_type, status, approver_notes) VALUES (:id, :step, :step_order, :dept_id, 'Department', :status, :notes)");
                $rStmt->execute([
                    'id' => $reportId,
                    'step' => $stepNumber,
                    'step_order' => $stepNumber,
                    'dept_id' => $deptId,
                    'status' => $routeStatus,
                    'notes' => $approverNotes
                ]);
                $stepNumber++;
            }
            $rStmt = $pdo->prepare("INSERT INTO report_workflow_routes (report_id, step_number, step_order, department_id, destination_type, destination_id, status, approver_notes) VALUES (:id, :step, :step_order, NULL, 'Resident', :destination_id, 'Pending', 'Final destination: Resident')");
            $rStmt->execute(['id' => $reportId, 'step' => $stepNumber, 'step_order' => $stepNumber, 'destination_id' => $clientId]);

            $selectedDeptNames = [];
            foreach ($workflowDeptIds as $index => $deptId) {
                $departmentLabel = $deptNameMap[$deptId] ?? 'Department ' . $deptId;
                if ($index === 0 && !empty($selectedService['service_name'])) {
                    $departmentLabel .= ' - ' . $selectedService['service_name'];
                }
                $selectedDeptNames[] = $departmentLabel;
            }
            $selectedDeptNames[] = 'Resident';
            $logRemarks = 'Document created and routed through: ' . implode(', ', $selectedDeptNames);
            if ($isClientSubmission && $processingTimeAcknowledged) {
                $logRemarks .= '. Client acknowledged the normal review and processing timeline and understood that immediate approval is not guaranteed.';
            }

            $logStmt = $pdo->prepare("INSERT INTO report_logs (report_id, old_department, new_department, action, remarks, updated_by) VALUES (:report_id, :sender_dept, :dept_id, 'created', :remarks, :user_id)");
            $logStmt->execute([
                'report_id' => $reportId,
                'sender_dept' => $senderDeptId,
                'dept_id' => $firstReceivingDeptId,
                'remarks' => $logRemarks,
                'user_id' => $user['id']
            ]);

            $senderName = $user['department_name'] ?? 'Originating Office';
            sendDepartmentNotification($pdo, $firstReceivingDeptId, $trackingNo, "{$senderName} created and routed Document {$trackingNo} to your department.");
            sendSystemNotification($pdo, $trackingNo, "New document {$trackingNo} ('{$title}') was submitted and routed for processing.");
            
            // Send in-app notification to client
            try {
                if ($clientId > 0) {
                    sendUserNotification($pdo, $clientId, $trackingNo, "A new document record {$trackingNo} ('{$title}') has been created for your request.");
                }
            } catch (Exception $e) {
                error_log('Failed to send in-app user notification on document creation: ' . $e->getMessage());
            }

            // Prepare client email notification
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $detailsUrl = "$protocol://$host/report_details.php?id=$reportId";
            $loginUrl = "$protocol://$host/login.php";

            $deptListStr = implode(' ➔ ', $selectedDeptNames);

            $subject = "Document Created: Unique ID. {$trackingNo} | Document Tracking System";
            $body = "Hello {$clientName},\n\n" .
                    "Good day!\n\n" .
                    "A new document tracking record has been created for your request.\n\n" .
                    "=======================================================\n" .
                    "DOCUMENT CREATION DETAILS\n" .
                    "=======================================================\n" .
                    "Tracking Number : {$trackingNo}\n" .
                    "Document Title  : {$title}\n" .
                    "Originating Office : {$senderName}\n" .
                    "Routed Steps    : {$deptListStr}\n" .
                    "Status          : Assigned & In Transit\n" .
                    "=======================================================\n\n";

            $body .= "You can sign in using your Google account to monitor the real-time progress and tracking details of your document:\n" .
                     "Document Tracking Link : {$detailsUrl}\n\n";

            $body .= "Thank you,\nDocument Tracking System";

            $emailStmt = $pdo->prepare('INSERT INTO email_outbox (recipient, subject, body) VALUES (:recipient, :subject, :body)');
            $emailStmt->execute([
                'recipient' => $clientEmail,
                'subject' => $subject,
                'body' => $body,
            ]);

            $pdo->commit();
            header("Location: report_details.php?id=" . $reportId . "&created=1");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error creating document: " . $e->getMessage();
        }
        }
    }
}

$selectedDepartmentIds = $receivingDeptIds ?? [];
if (!empty($user['department_id']) && ($user['role'] === 'department_admin' || $user['role'] === 'department_staff' || $user['role'] === 'department')) {
    $userDeptId = (int)$user['department_id'];
    if ($userDeptId > 0) {
        $selectedDepartmentIds[] = $userDeptId;
        $selectedDepartmentIds = array_values(array_unique($selectedDepartmentIds));
    }
}

require_once APP_ROOT . '/includes/header.php';
?>

<div class="client-submission-shell full-width-submission">
    <div class="client-submission-header">
        <h1 style="margin-top: 0.5rem;">Create New Document / Request</h1>
        <p>Tell us what you need, choose the responsible office, and we will route your document automatically.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card client-submission-card">
        <form method="POST" action="submit_report.php" enctype="multipart/form-data" class="client-submission-form">
            <div class="client-form-section" style="margin-bottom: 1rem;">
                <div class="client-form-grid">
                    <div class="form-group">
                        <label>Submission Type</label>
                        <div class="form-control" aria-label="Submission Type" style="background:#f8fafc;">
                            <?= $isClientSubmission ? 'Online' : 'Walk-in' ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Submitted By</label>
                        <div class="form-control" aria-label="Submitted By" style="background:#f8fafc;">
                            <?= $isClientSubmission ? 'Client' : 'Department' ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($isClientSubmission): ?>
                <div class="client-form-section client-account-summary">
                    <div class="client-section-heading">
                        <span class="client-section-number">1</span>
                        <div><strong>Your account</strong><small>These details come from your signed-in account.</small></div>
                    </div>
                    <div class="client-form-grid">
                    <div class="form-group">
                        <label for="account_name">Full Name</label>
                        <input id="account_name" class="form-control" value="<?= htmlspecialchars($user['name'] ?? '') ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="account_email">Google Email</label>
                        <input id="account_email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly>
                    </div>
                    </div>
                </div>
                <div class="client-form-section client-routing-panel">
                    <div class="client-section-heading">
                        <span class="client-section-number">2</span>
                        <div><strong>Choose where to send it</strong><small>Available services update automatically.</small></div>
                    </div>
                    <div class="client-form-grid">
                    <div class="form-group">
                        <label for="client_department_id">Department / Office</label>
                        <select id="client_department_id" name="department_id" class="form-control" required>
                            <option value="">Select department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>"><?= htmlspecialchars($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="client_service_id">Service / Activity</label>
                        <select id="client_service_id" name="service_id" class="form-control" required disabled>
                            <option value="">Select department first</option>
                        </select>
                    </div>
                </div>
                <div id="client-activity-details" class="form-group" style="display:none; margin-top:1rem;">
                    <label>Activity Details</label>
                    <small style="display:block;color:#64748b;margin-bottom:.5rem;">Please complete the information requested by this department.</small>
                    <div id="client-activity-detail-fields"></div>
                </div>
                <div id="client-service-documents" class="form-group" style="display:none; margin-top:1rem;">
                    <strong>Forms / Requirements</strong>
                    <ul id="client-service-document-links" style="margin:.5rem 0 0;padding-left:1.25rem;"></ul>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const department = document.getElementById('client_department_id');
                    const service = document.getElementById('client_service_id');
                    const detailBox = document.getElementById('client-activity-details');
                    const detailFields = document.getElementById('client-activity-detail-fields');
                    const serviceDocumentsBox = document.getElementById('client-service-documents');
                    const serviceDocumentLinks = document.getElementById('client-service-document-links');
                    const services = <?= json_encode($servicesByDepartment, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    const maxUploadFileBytes = <?= $maxUploadFileBytes ?>;
                    function renderServiceDocuments(item) {
                        serviceDocumentLinks.innerHTML = '';
                        const documents = item && Array.isArray(item.documents) ? item.documents : [];
                        serviceDocumentsBox.style.display = documents.length ? 'block' : 'none';
                        documents.forEach(function (serviceFile) {
                            const listItem = document.createElement('li');
                            const link = document.createElement('a');
                            link.href = 'service_file_download.php?id=' + encodeURIComponent(serviceFile.id);
                            link.textContent = serviceFile.original_name || 'Download form';
                            link.setAttribute('download', '');
                            listItem.appendChild(link);
                            serviceDocumentLinks.appendChild(listItem);
                        });
                    }
                    function renderActivityDetails(item) {
                        const rows = item && Array.isArray(item.detail_rows) ? item.detail_rows : [];
                        detailFields.innerHTML = '';
                        detailBox.style.display = rows.length ? 'block' : 'none';
                        rows.forEach(function (row, index) {
                            const group = document.createElement('div');
                            group.className = 'form-group';
                            const label = document.createElement('label');
                            const input = document.createElement('input');
                            input.type = row.type === 'file' ? 'file' : 'text';
                            input.name = row.type === 'file' ? 'activity_detail_file[' + index + ']' : 'activity_detail_answer[' + index + ']';
                            input.id = 'activity_detail_' + index;
                            label.htmlFor = input.id;
                            label.textContent = row.label || 'Additional detail';
                            if (row.type === 'file') {
                                input.accept = '.pdf,.doc,.docx,.xls,.xlsx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                            }
                            input.className = 'form-control';
                            input.placeholder = row.value || 'Enter information';
                            input.required = Boolean(row.required);
                            group.appendChild(label);
                            group.appendChild(input);
                            if (row.type === 'file') {
                                const fileError = document.createElement('small');
                                fileError.style.cssText = 'display:block;color:#b91c1c;margin-top:.25rem;';
                                fileError.setAttribute('role', 'alert');
                                input.addEventListener('change', function () {
                                    const file = input.files && input.files[0];
                                    const isAllowedType = !file || /\.(pdf|doc|docx|xls|xlsx)$/i.test(file.name);
                                    const isWithinSizeLimit = !file || file.size <= maxUploadFileBytes;
                                    const message = !isAllowedType
                                        ? 'Upload a PDF, Word (.doc/.docx), or Excel (.xls/.xlsx) file only. Images and videos are not allowed.'
                                        : (!isWithinSizeLimit ? 'Each file must be 25 MB or smaller.' : '');
                                    input.setCustomValidity(message);
                                    fileError.textContent = message;
                                });
                                group.appendChild(fileError);
                            }
                            detailFields.appendChild(group);
                        });
                    }
                    department.addEventListener('change', function () {
                        const options = services[this.value] || [];
                        service.innerHTML = '';
                        service.add(new Option(options.length ? 'Select service or activity' : 'No active services available', ''));
                        options.forEach(function (item) {
                            service.add(new Option(item.service_name + ' (' + item.priority + ')', item.id));
                        });
                        service.disabled = options.length === 0;
                        renderActivityDetails(null);
                        renderServiceDocuments(null);
                    });
                    service.addEventListener('change', function () {
                        const selected = (services[department.value] || []).find(function (item) {
                            return String(item.id) === service.value;
                        });
                        renderActivityDetails(selected);
                        renderServiceDocuments(selected);
                    });
                });
                </script>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div class="form-group">
                        <label for="client_name">Client Full Name</label>
                        <input type="text" id="client_name" name="client_name" class="form-control" placeholder="e.g. Juan Dela Cruz" required>
                    </div>
                    <div class="form-group">
                        <label for="client_email">Client Email Address</label>
                        <input type="email" id="client_email" name="client_email" class="form-control" list="approved_client_emails" placeholder="e.g. juan@example.com" required>
                        <datalist id="approved_client_emails">
                            <?php foreach ($approvedClients as $client): ?>
                                <option value="<?= htmlspecialchars($client['email']) ?>"><?= htmlspecialchars($client['name']) ?></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            document.addEventListener('DOMContentLoaded', function () {
                const emailInput = document.getElementById('client_email');
                const nameInput = document.getElementById('client_name');
                const approvedClientMap = <?php echo json_encode($approvedClientMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

                if (emailInput && nameInput) {
                    emailInput.addEventListener('change', function () {
                        const value = (this.value || '').trim().toLowerCase();
                        const matchedName = approvedClientMap[value];
                        if (matchedName) {
                            nameInput.value = matchedName;
                        }
                    });
                }

                const form = document.querySelector('.client-submission-form');
                const attachmentInput = document.getElementById('attachment');
                const attachmentFileError = document.getElementById('attachment_file_error');
                if (attachmentInput && attachmentFileError) {
                    attachmentInput.addEventListener('change', function () {
                        const file = attachmentInput.files && attachmentInput.files[0];
                        const isAllowedType = !file || /\.(pdf|doc|docx|xls|xlsx)$/i.test(file.name);
                        const isWithinSizeLimit = !file || file.size <= <?= $maxUploadFileBytes ?>;
                        const errorMessage = !isAllowedType
                            ? 'Upload a PDF, Word (.doc/.docx), or Excel (.xls/.xlsx) file only. Images and videos are not allowed.'
                            : (!isWithinSizeLimit ? 'Each file must be 25 MB or smaller.' : '');
                        attachmentInput.setCustomValidity(errorMessage);
                        attachmentFileError.textContent = errorMessage;
                    });
                }
                const textFields = ['title', 'category', 'purpose', 'additional_information']
                    .map(id => document.getElementById(id))
                    .filter(Boolean);
                const keyboardPattern = /(qwerty|asdf|zxcv|poiuy|lkjh|mnbv)/i;
                const commonWord = /\b(the|for|and|request|document|permit|application|report|office|service|assistance|certificate|clearance|endorsement)\b/i;
                const validateText = (input) => {
                    const value = input.value.trim();
                    const required = input.required;
                    let message = '';
                    if (!value && required) {
                        message = `${input.labels[0]?.innerText || 'This field'} is required.`;
                    } else if (value && (value.length < 3 || value.length > 255)) {
                        message = 'Please enter between 3 and 255 characters.';
                    } else if (value && !/[A-Za-zÀ-ÿ]/.test(value)) {
                        message = 'Please enter valid letters.';
                    } else if (value && !/[aeiou]/i.test(value) && !commonWord.test(value) && !/^[A-Z]{1,5}$/.test(value.replace(/[^A-Za-z]/g, ''))) {
                        message = 'Please enter a meaningful name or description.';
                    } else if (value && (/[bcdfghjklmnpqrstvwxyz]{5,}/i.test(value) || /(.)\1{3,}/i.test(value) || keyboardPattern.test(value))) {
                        message = 'Please enter a meaningful name or description.';
                    }
                    input.setCustomValidity(message);
                };
                textFields.forEach(input => {
                    input.minLength = 3;
                    input.maxLength = 255;
                    input.addEventListener('input', () => validateText(input));
                    input.addEventListener('blur', () => validateText(input));
                });
                if (form) {
                    form.addEventListener('submit', () => textFields.forEach(validateText));
                }
            });
            </script>

            <div class="client-form-section client-request-section">
                <div class="client-section-heading">
                    <span class="client-section-number"><?= $isClientSubmission ? '3' : '1' ?></span>
                    <div><strong>Request details</strong><small>Use a clear title and explain what you need.</small></div>
                </div>
            <?php if (!$isClientSubmission): ?>
                <div class="form-group">
                    <label for="title">Document Title</label>
                    <input type="text" id="title" name="title" class="form-control" minlength="3" maxlength="255" placeholder="e.g. Request for Budget Endorsement for Infrastructure" required>
                </div>
            <?php endif; ?>

            <?php if (!$isClientSubmission): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="category">Document Type / Category</label>
                    <input type="text" id="category" name="category" class="form-control" minlength="3" maxlength="255" placeholder="e.g. Permit, Request, Endorsement">
                </div>
                <div class="form-group">
                    <label for="purpose">Purpose</label>
                    <input type="text" id="purpose" name="purpose" class="form-control" minlength="3" maxlength="255" placeholder="Purpose of this document">
                </div>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="additional_information">Additional Information</label>
                <textarea id="additional_information" name="additional_information" class="form-control" minlength="3" maxlength="255" rows="3" placeholder="Other information for the receiving department"></textarea>
            </div>

            <?php if (!$hideAttachment): ?>
                <div class="form-group">
                    <label for="attachment">Attachment</label>
                    <input type="file" id="attachment" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    <small style="display:block;color:#64748b;margin-top:.25rem;">PDF, Word (.doc/.docx), or Excel (.xls/.xlsx), up to 25 MB per file.</small>
                    <small id="attachment_file_error" style="display:block;color:#b91c1c;margin-top:.25rem;" role="alert"></small>
                </div>
            <?php endif; ?>

            <?php if ($isClientSubmission): ?>
                <div class="form-group">
                    <label for="purpose">Purpose</label>
                    <input type="text" id="purpose" name="purpose" class="form-control" minlength="3" maxlength="255" placeholder="Why are you submitting this document?">
                </div>
            <?php endif; ?>

            </div>

            <?php if (!$isClientSubmission): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group" style="grid-column: 1 / -1;">
                    <fieldset style="border: 0; padding: 0; margin: 0;">
                        <legend style="padding: 0; margin-bottom: 0.5rem; font-weight: 600; color: #334155;">Workflow Departments</legend>

                        <label>Select departments in the desired route order <span class="required-field-indicator" aria-hidden="true">*</span></label>
                        <small style="display:block; color:#64748b; margin-top:0.25rem;">Check the offices in order. The last office you check will be the last office before Resident.</small>
                        <div id="workflow-department-search" style="display:flex; gap:0.5rem; margin-top:0.5rem; margin-bottom:0.65rem;">
                            <input type="search" id="departmentSearch" class="form-control" placeholder="Search departments..." aria-label="Search departments">
                            <button type="button" id="clearDepartmentSearch" class="btn btn-primary" >Clear</button>
                        </div>
                        <div id="departmentCheckboxes" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:0.55rem;">
                            <?php $departmentIcons = ['bi-bank', 'bi-clipboard', 'bi-people', 'bi-tools', 'bi-cash-coin', 'bi-bar-chart', 'bi-wallet2', 'bi-scale', 'bi-rulers', 'bi-tree', 'bi-cone-striped', 'bi-briefcase', 'bi-handshake', 'bi-house', 'bi-file-earmark-text', 'bi-box-seam', 'bi-building', 'bi-shield', 'bi-fire', 'bi-person', 'bi-hospital', 'bi-flower1']; ?>
                            <?php foreach ($departments as $index => $dept): ?>
                                <label class="department-option" data-department-name="<?= htmlspecialchars(strtolower($dept['name']), ENT_QUOTES) ?>" style="display:flex; align-items:center; gap:0.65rem; padding:0.7rem 0.75rem; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer; background:#fff;">
                                    <input type="checkbox" class="workflow-department" value="<?= $dept['id'] ?>" data-name="<?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>">
                                    <span aria-hidden="true" style="font-size:1.15rem; width:26px; text-align:center;"><i class="bi <?= $departmentIcons[$index % count($departmentIcons)] ?>"></i></span>
                                    <span><?= htmlspecialchars($dept['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" id="originating_department_id" name="originating_department_id" value="">
                        <div style="text-align:center; font-size:1.3rem; margin:0.65rem 0;">↓</div>
                        <strong>Workflow order</strong>
                        <ol id="workflowSteps" style="margin:0.5rem 0 0; padding-left:2rem;"></ol>
                        <div id="workflowHiddenInputs"></div>
                        <div id="workflowError" class="alert alert-danger" style="display:none; margin-top:0.75rem;"></div>
                        <small style="color: #64748b; display: block; margin-top: 0.35rem;">Resident is automatically added as the final destination.</small>
                    </fieldset>
                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const first = document.getElementById('originating_department_id');
                        const checkboxes = document.querySelectorAll('.workflow-department');
                        const departmentSearch = document.getElementById('departmentSearch');
                        const departmentOptions = document.querySelectorAll('.department-option');
                        const list = document.getElementById('workflowSteps');
                        const hiddenInputs = document.getElementById('workflowHiddenInputs');
                        const form = document.querySelector('form[action="submit_report.php"]');
                        let selected = [];
                        function render() {
                            first.value = selected.length ? selected[0] : '';
                            list.innerHTML = '';
                            selected.forEach(function(id, index) {
                                const checkbox = document.querySelector('.workflow-department[value="' + id + '"]');
                                const item = document.createElement('li');
                                let departmentLabel = checkbox.dataset.name;
                                item.textContent = departmentLabel + (index === 0 ? ' (First Department)' : '');
                                list.appendChild(item);
                            });
                            if (selected.length) { const final = document.createElement('li'); final.textContent = 'Resident (Final Destination)'; list.appendChild(final); }
                            hiddenInputs.innerHTML = '';
                            selected.slice(1).forEach(function(id) { const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'department_id[]'; hidden.value = id; hiddenInputs.appendChild(hidden); });
                        }
                        checkboxes.forEach(function(checkbox) { checkbox.addEventListener('change', function() { const id = Number(this.value); if (this.checked) { if (selected.indexOf(id) === -1) selected.push(id); } else { selected = selected.filter(function(selectedId) { return selectedId !== id; }); } render(); }); });
                        departmentSearch.addEventListener('input', function() { const query = this.value.trim().toLowerCase(); departmentOptions.forEach(function(option) { option.style.display = !query || option.dataset.departmentName.indexOf(query) !== -1 ? 'flex' : 'none'; }); });
                        document.getElementById('clearDepartmentSearch').addEventListener('click', function() { departmentSearch.value = ''; departmentSearch.dispatchEvent(new Event('input')); departmentSearch.focus(); });
                        form.addEventListener('submit', function(event) { if (!first.value || selected.length === 0) { event.preventDefault(); const error = document.getElementById('workflowError'); error.textContent = 'Select a first department and at least one succeeding department.'; error.style.display = 'block'; } });
                        render();
                    });
                    </script>
                </div>
            <?php endif; ?>

                <div class="form-group">
                    <label for="priority">Priority Level</label>
                    <select id="priority" name="priority" class="form-control" required>
                        <option value="Low" <?= $priority === 'Low' ? 'selected' : '' ?>>Low</option>
                        <option value="Medium" <?= $priority === 'Medium' ? 'selected' : '' ?>>Medium</option>
                        <option value="High" <?= $priority === 'High' ? 'selected' : '' ?>>High</option>
                        <option value="Urgent" <?= $priority === 'Urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                    <small style="display:block;color:#64748b;margin-top:.35rem;">Choose how soon this document is needed. Select Urgent only when it is needed as soon as possible, and explain the reason in the Purpose or Additional Information field.</small>
                </div>
                <?php if ($isClientSubmission): ?>
                    <div class="form-group" style="margin-top:.5rem;padding:1rem;border:1px solid #cbd5e1;border-radius:10px;background:#f8fafc;">
                        <div style="display:flex;align-items:flex-start;gap:.65rem;">
                            <input
                                type="checkbox"
                                id="processing_time_acknowledged"
                                name="processing_time_acknowledged"
                                value="1"
                                required
                                <?= $processingTimeAcknowledged ? 'checked' : '' ?>
                                style="width:1.1rem;height:1.1rem;flex:0 0 auto;margin-top:.15rem;"
                            >
                            <label for="processing_time_acknowledged" style="margin:0;line-height:1.5;">
                                I understand that my document will undergo the standard review and processing procedures, and approval times may vary. Selecting a priority level, including Urgent, does not guarantee immediate approval; approval remains subject to document review and the required processing steps.
                            </label>
                        </div>
                        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$processingTimeAcknowledged): ?>
                            <small style="display:block;margin:.5rem 0 0 1.75rem;color:#b91c1c;" role="alert">Kailangan mong kumpirmahin ito bago isumite ang dokumento.</small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="client-submission-actions">
                <a href="dashboard.php" class="btn btn-secondary client-submit-action-button">Cancel</a>
                <button type="submit" class="btn btn-primary client-submit-action-button">Submit</button>
            </div>
        </form>
    </div>
    <style>
        #upload-size-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            width: min(92vw, 440px);
            max-height: min(85vh, 520px);
            margin: 0;
            padding: 0;
            transform: translate(-50%, -50%);
            overflow: auto;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, .25);
        }

        #upload-size-modal::backdrop {
            background: rgba(15, 23, 42, .55);
        }
    </style>
    <dialog id="upload-size-modal" aria-labelledby="upload-size-modal-title">
        <div style="padding:1.5rem;">
            <h2 id="upload-size-modal-title" style="margin:0 0 .75rem;font-size:1.25rem;">File size limit exceeded</h2>
            <p id="upload-size-modal-message" style="margin:0 0 1.25rem;color:#475569;">Each file must be 25 MB or smaller. Please replace any file that exceeds the limit.</p>
            <div style="display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-primary" id="upload-size-modal-close">OK</button>
            </div>
        </div>
    </dialog>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('.client-submission-form');
        const modal = document.getElementById('upload-size-modal');
        const message = document.getElementById('upload-size-modal-message');
        const closeButton = document.getElementById('upload-size-modal-close');
        const maxUploadFileBytes = <?= $maxUploadFileBytes ?>;
        const maxRequestBytes = <?= $postMaxBytes ?>;
        const requestSafetyBytes = 1024 * 1024;

        function openLimitModal(text) {
            message.textContent = text;
            if (typeof modal.showModal === 'function' && !modal.open) {
                modal.showModal();
            } else {
                modal.setAttribute('open', '');
            }
            closeButton.focus();
        }

        closeButton.addEventListener('click', function () {
            if (typeof modal.close === 'function') {
                modal.close();
            } else {
                modal.removeAttribute('open');
            }
        });

        document.addEventListener('change', function (event) {
            const input = event.target;
            if (!input.matches('input[type="file"]')) {
                return;
            }
            const file = input.files && input.files[0];
            if (file && file.size > maxUploadFileBytes) {
                const fileSizeMb = (file.size / (1024 * 1024)).toFixed(1);
                openLimitModal('"' + file.name + '" is ' + fileSizeMb + ' MB. Each file must be 25 MB or smaller. Please choose a smaller file.');
            }
        });

        form.addEventListener('submit', function (event) {
            const selectedFiles = Array.from(form.querySelectorAll('input[type="file"]'))
                .flatMap(function (input) {
                    return Array.from(input.files || []);
                });
            const oversizedFile = selectedFiles.find(function (file) {
                return file.size > maxUploadFileBytes;
            });
            if (oversizedFile) {
                event.preventDefault();
                const fileSizeMb = (oversizedFile.size / (1024 * 1024)).toFixed(1);
                openLimitModal('"' + oversizedFile.name + '" is ' + fileSizeMb + ' MB. Each file must be 25 MB or smaller. Please replace this file before submitting.');
                return;
            }

            const totalFileBytes = selectedFiles.reduce(function (total, file) {
                return total + file.size;
            }, 0);
            if (maxRequestBytes > 0 && totalFileBytes + requestSafetyBytes > maxRequestBytes) {
                event.preventDefault();
                const totalFileMb = (totalFileBytes / (1024 * 1024)).toFixed(1);
                const serverLimitMb = (maxRequestBytes / (1024 * 1024)).toFixed(0);
                openLimitModal('Your selected files total ' + totalFileMb + ' MB, which exceeds this server\'s ' + serverLimitMb + ' MB request limit after form data is included. Each file may still be up to 25 MB. Please ask the hosting provider to increase PHP post_max_size before submitting files of this combined size.');
            }
        });

        if (<?= $postExceedsLimit ? 'true' : 'false' ?>) {
            openLimitModal('This request exceeds the server upload limit. Each file may be up to 25 MB, but the combined request is too large for this server. Please submit fewer files at a time.');
        }
    });
    </script>
    <?php require_once APP_ROOT . '/includes/footer.php'; ?>
</div>
  
