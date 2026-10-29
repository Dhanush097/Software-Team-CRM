<?php
/**
 * CompanyProjectMatcher
 * Handles automatic, case-insensitive, normalized association between Companies and Projects.
 */

class CompanyProjectMatcher {
    /**
     * Normalize a name for strict comparison:
     * - Trims leading and trailing whitespace
     * - Collapses internal multiple whitespaces to a single space
     * - Converts to lowercase UTF-8
     */
    public static function normalize(?string $name): string {
        if ($name === null) return '';
        $trimmed = trim(preg_replace('/\s+/u', ' ', $name));
        return mb_strtolower($trimmed, 'UTF-8');
    }

    /**
     * Check if two names strictly match after normalization.
     * Prevents partial matches (e.g. 'ABC' != 'ABC Solutions').
     */
    public static function isMatch(?string $name1, ?string $name2): bool {
        $n1 = self::normalize($name1);
        $n2 = self::normalize($name2);
        return ($n1 !== '' && $n1 === $n2);
    }

    /**
     * Find matching company for a given project title.
     */
    public static function findCompanyForProject(PDO $pdo, string $projectTitle): array {
        $norm = self::normalize($projectTitle);
        if ($norm === '') {
            return ['status' => 'none', 'company' => null];
        }

        $stmt = $pdo->prepare("
            SELECT id, name, industry, website, email, phone, logo_url
            FROM crm_companies 
            WHERE LOWER(TRIM(name)) = ?
        ");
        $stmt->execute([$norm]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($matches) === 1) {
            return [
                'status' => 'exact',
                'company' => $matches[0]
            ];
        } elseif (count($matches) > 1) {
            return [
                'status' => 'ambiguous',
                'count' => count($matches),
                'companies' => $matches,
                'company' => null
            ];
        }

        return ['status' => 'none', 'company' => null];
    }

    /**
     * Find matching projects for a given company name.
     */
    public static function findProjectsForCompany(PDO $pdo, string $companyName): array {
        $norm = self::normalize($companyName);
        if ($norm === '') {
            return ['status' => 'none', 'projects' => []];
        }

        $stmt = $pdo->prepare("
            SELECT id, title, company_id, client_name, status, category, budget
            FROM crm_projects 
            WHERE LOWER(TRIM(title)) = ?
        ");
        $stmt->execute([$norm]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($matches)) {
            return ['status' => 'matches', 'projects' => $matches];
        }

        return ['status' => 'none', 'projects' => []];
    }

    /**
     * Link all unambiguous, non-conflicting projects matching this company.
     * Preserves existing explicit relationships to other companies.
     */
    public static function syncProjectsForCompany(PDO $pdo, string $companyId, string $companyName, bool $isNameChange = false): array {
        $norm = self::normalize($companyName);
        if ($norm === '') {
            return ['linked' => [], 'skipped_conflict' => [], 'flagged_ambiguous' => false];
        }

        // Check if multiple companies have this exact normalized name
        $cStmt = $pdo->prepare("SELECT id, name FROM crm_companies WHERE LOWER(TRIM(name)) = ?");
        $cStmt->execute([$norm]);
        $cMatches = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($cMatches) > 1) {
            return [
                'linked' => [],
                'skipped_conflict' => [],
                'flagged_ambiguous' => true,
                'message' => 'Multiple companies share this name; automatic linking skipped for safety.'
            ];
        }

        // Find projects whose title matches this company name
        $stmt = $pdo->prepare("SELECT id, title, company_id, client_name FROM crm_projects WHERE LOWER(TRIM(title)) = ?");
        $stmt->execute([$norm]);
        $matchingProjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $linked = [];
        $conflicts = [];

        foreach ($matchingProjects as $p) {
            // Already linked to this company
            if ($p['company_id'] === $companyId) {
                continue;
            }

            // Explicitly linked to another company -> preserve existing explicit relationship
            if (!empty($p['company_id']) && $p['company_id'] !== $companyId) {
                $conflicts[] = $p;
                continue;
            }

            // Unlinked project -> link it!
            $clientName = (!empty($p['client_name']) && $p['client_name'] !== 'Client') ? $p['client_name'] : $companyName;
            $upd = $pdo->prepare("UPDATE crm_projects SET company_id = ?, client_name = ? WHERE id = ?");
            $upd->execute([$companyId, $clientName, $p['id']]);
            $linked[] = [
                'id' => $p['id'],
                'title' => $p['title']
            ];
        }

        return [
            'linked' => $linked,
            'skipped_conflict' => $conflicts,
            'flagged_ambiguous' => false
        ];
    }

    /**
     * Safe backfill for all existing records.
     */
    public static function backfillAll(PDO $pdo): array {
        $companies = $pdo->query("SELECT id, name FROM crm_companies")->fetchAll(PDO::FETCH_ASSOC);
        $projects = $pdo->query("SELECT id, title, company_id, client_name FROM crm_projects")->fetchAll(PDO::FETCH_ASSOC);

        $compByNorm = [];
        foreach ($companies as $c) {
            $norm = self::normalize($c['name']);
            if (!isset($compByNorm[$norm])) $compByNorm[$norm] = [];
            $compByNorm[$norm][] = $c;
        }

        $projByNorm = [];
        foreach ($projects as $p) {
            $norm = self::normalize($p['title']);
            if (!isset($projByNorm[$norm])) $projByNorm[$norm] = [];
            $projByNorm[$norm][] = $p;
        }

        $linked = [];
        $flagged = [];
        $conflicts = [];

        foreach ($compByNorm as $norm => $cList) {
            if (!isset($projByNorm[$norm])) continue;
            $pList = $projByNorm[$norm];

            if (count($cList) === 1 && count($pList) === 1) {
                $comp = $cList[0];
                $proj = $pList[0];

                if (!empty($proj['company_id']) && $proj['company_id'] !== $comp['id']) {
                    $conflicts[] = [
                        'project' => $proj,
                        'company' => $comp,
                        'reason' => 'Project already explicitly assigned to different company ID ' . $proj['company_id']
                    ];
                    continue;
                }

                if (empty($proj['company_id'])) {
                    $upd = $pdo->prepare("UPDATE crm_projects SET company_id = ? WHERE id = ?");
                    $upd->execute([$comp['id'], $proj['id']]);
                    $linked[] = [
                        'project_id' => $proj['id'],
                        'project_title' => $proj['title'],
                        'company_id' => $comp['id'],
                        'company_name' => $comp['name']
                    ];
                }
            } else {
                $flagged[] = [
                    'normalized_name' => $norm,
                    'companies' => $cList,
                    'projects' => $pList,
                    'reason' => 'Multiple records with identical normalized name'
                ];
            }
        }

        return [
            'linked_count' => count($linked),
            'linked' => $linked,
            'flagged_count' => count($flagged),
            'flagged' => $flagged,
            'conflicts_count' => count($conflicts),
            'conflicts' => $conflicts
        ];
    }
}
