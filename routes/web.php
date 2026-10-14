<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\Platform\PlatformAdminController;
use App\Http\Controllers\Portal\AcademicStructureController;
use App\Http\Controllers\Portal\AdmissionsPipelineController;
use App\Http\Controllers\Portal\AppointmentController;
use App\Http\Controllers\Portal\AssessmentSettingsController;
use App\Http\Controllers\Portal\AssignmentController;
use App\Http\Controllers\Portal\AttendanceController;
use App\Http\Controllers\Portal\ClubController;
use App\Http\Controllers\Portal\CmsMediaController;
use App\Http\Controllers\Portal\CmsPageController;
use App\Http\Controllers\Portal\CmsPostController;
use App\Http\Controllers\Portal\CompetencyController;
use App\Http\Controllers\Portal\ConsentController;
use App\Http\Controllers\Portal\CoverageController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DirectorDocumentWorklistController;
use App\Http\Controllers\Portal\DocumentApprovalController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\DocumentVersionController;
use App\Http\Controllers\Portal\EnrollmentVerificationController;
use App\Http\Controllers\Portal\FinanceController;
use App\Http\Controllers\Portal\FinanceSandboxController;
use App\Http\Controllers\Portal\GradebookController;
use App\Http\Controllers\Portal\HealthController;
use App\Http\Controllers\Portal\LessonController;
use App\Http\Controllers\Portal\MealController;
use App\Http\Controllers\Portal\MemberController;
use App\Http\Controllers\Portal\MessageController;
use App\Http\Controllers\Portal\MyAssignmentsController;
use App\Http\Controllers\Portal\MyDocumentWorkController;
use App\Http\Controllers\Portal\PickupController;
use App\Http\Controllers\Portal\PortalRoleController;
use App\Http\Controllers\Portal\PortfolioController;
use App\Http\Controllers\Portal\PortfolioExtrasController;
use App\Http\Controllers\Portal\ReportCardController;
use App\Http\Controllers\Portal\SchoolPulseController;
use App\Http\Controllers\Portal\ServicesController;
use App\Http\Controllers\Portal\StudentProgressController;
use App\Http\Controllers\Portal\SubstitutionController;
use App\Http\Controllers\Portal\SupportAccessController;
use App\Http\Controllers\Portal\TeacherController;
use App\Http\Controllers\Portal\TeacherWorkspaceController;
use App\Http\Controllers\Portal\TransportController;
use App\Http\Controllers\Public\AdmissionLeadController;
use App\Http\Controllers\Public\InvitationController;
use App\Http\Controllers\Public\LibraryCatalogController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\PostController;
use App\Http\Controllers\Public\ReportCardVerificationController;
use App\Http\Controllers\Public\RobotsController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Controllers\Public\TeacherController as PublicTeacherController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home'])->name('home');

Route::post('/admissions/leads', [AdmissionLeadController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('admissions.leads.store');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsController::class)->name('robots');

Route::get('/news', [PostController::class, 'index'])->name('news.index');
Route::get('/news/{slug}', [PostController::class, 'show'])->name('news.show');

Route::get('/library', [LibraryCatalogController::class, 'index'])->name('library.index');

Route::get('/teachers', [PublicTeacherController::class, 'index'])->name('teachers.public-index');
Route::get('/teachers/{slug}', [PublicTeacherController::class, 'show'])->name('teachers.public-show');

// --- Tenant invitation accept flow (public, unauthenticated) — members/invite
// feature. Added at the end of the public route block so parallel agent
// edits above are easy to merge around. ---
Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1')->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

// Payment provider notifications (signature-authenticated, CSRF-exempt).
Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.payments');

// Public authenticity check for a printed report card (no marks shown).
Route::get('verify/report-card/{code}', ReportCardVerificationController::class)
    ->where('code', '[A-Za-z0-9]{6,16}')
    ->middleware('throttle:30,1')
    ->name('report-cards.verify');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // CLAUDE-PLATFORM-MODULES §3 names the day centre /portal/today; it is
    // the same role-aware start page.
    Route::get('portal/today', DashboardController::class)->name('portal.today');
    Route::get('portal/teacher', [TeacherWorkspaceController::class, 'index'])->name('teacher-workspace.index');
    Route::get('portal/teacher/lessons/{lesson}', [TeacherWorkspaceController::class, 'lesson'])->name('teacher-workspace.lesson');

    // --- Self-service enrollment identity verification (student/guardian
    // claims a roster identity; admin/director approves via the members
    // screen's decideEnrollment action below). ---
    Route::get('portal/verify-enrollment', [EnrollmentVerificationController::class, 'show'])->name('enrollment-verification.show');
    Route::post('portal/verify-enrollment', [EnrollmentVerificationController::class, 'store'])->name('enrollment-verification.store');
    Route::post('portal/active-role', [PortalRoleController::class, 'update'])->name('portal.active-role.update');

    // --- Timetable (admin/academic_manager) — creates the subjects/lessons
    // rows the Substitution module's absence/coverage worklist depends on.
    // Before `index`/`storeSubject` existed, LessonController::store was
    // unreachable dead code from any real UI (no page posted to it, and
    // nothing anywhere could create a Subject) — confirmed as the
    // production root blocker in Substitution module verification. ---
    Route::get('portal/timetable', [LessonController::class, 'index'])->name('timetable.index');
    Route::post('portal/timetable/subjects', [LessonController::class, 'storeSubject'])->name('timetable.subjects.store');
    Route::post('lessons', [LessonController::class, 'store'])->name('lessons.store');
    // --- end Timetable ---

    Route::get('portal/students/{student}/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
    Route::post('portal/students/{student}/portfolio', [PortfolioController::class, 'store'])->name('portfolio.store');
    Route::get('portal/portfolio/review-queue', [PortfolioController::class, 'reviewQueue'])->name('portfolio.review-queue');
    Route::get('portal/portfolio/{portfolioItem}', [PortfolioController::class, 'show'])->name('portfolio.show');
    Route::post('portal/portfolio/{portfolioItem}/assets', [PortfolioController::class, 'storeAsset'])->name('portfolio.assets.store');
    Route::get('portal/portfolio/{portfolioItem}/assets/{asset}/download', [PortfolioController::class, 'downloadAsset'])
        ->middleware('signed')
        ->name('portfolio.assets.download');
    Route::post('portal/portfolio/{portfolioItem}/submit', [PortfolioController::class, 'submit'])->name('portfolio.submit');
    Route::post('portal/portfolio/{portfolioItem}/decide', [PortfolioController::class, 'decide'])->name('portfolio.decide');
    Route::post('portal/portfolio/{portfolioItem}/tags', [PortfolioExtrasController::class, 'setTags'])->name('portfolio.tags');
    Route::post('portal/portfolio/{portfolioItem}/archive', [PortfolioExtrasController::class, 'archive'])->name('portfolio.archive');
    Route::post('portal/students/{student}/achievements', [PortfolioExtrasController::class, 'award'])->name('portfolio.achievements.store');
    Route::get('portal/students/{student}/portfolio/export', [PortfolioExtrasController::class, 'export'])->name('portfolio.export');

    // --- Assignments & submissions (homework workflow) — teacher side under
    // portal/assignments, student side under portal/my-assignments. Mirrors
    // Portfolio's draft/published + ownership-check pattern. ---
    Route::get('portal/assignments', [AssignmentController::class, 'index'])->name('assignments.index');
    Route::post('portal/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
    Route::post('portal/assignments/{assignment}/publish', [AssignmentController::class, 'publish'])->name('assignments.publish');
    Route::post('portal/assignments/{assignment}/unpublish', [AssignmentController::class, 'unpublish'])->name('assignments.unpublish');
    Route::get('portal/assignments/{assignment}/submissions', [AssignmentController::class, 'submissions'])->name('assignments.submissions');
    Route::post('portal/assignments/{assignment}/submissions/{submission}/grade', [AssignmentController::class, 'grade'])->name('assignments.submissions.grade');
    Route::get('portal/assignments/{assignment}/submissions/{submission}/download', [AssignmentController::class, 'downloadSubmission'])
        ->middleware('signed')
        ->name('assignments.submissions.download');

    Route::get('portal/my-assignments', [MyAssignmentsController::class, 'index'])->name('my-assignments.index');
    Route::post('portal/my-assignments/{assignment}/submit', [MyAssignmentsController::class, 'submit'])->name('my-assignments.submit');
    // --- end Assignments ---

    Route::get('portal/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('portal/messages', [MessageController::class, 'store'])->middleware('throttle:20,1')->name('messages.store');
    Route::get('portal/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::post('portal/messages/{conversation}/reply', [MessageController::class, 'reply'])->middleware('throttle:60,1')->name('messages.reply');
    Route::get('portal/messages/{conversation}/attachments/{attachment}', [MessageController::class, 'downloadAttachment'])->name('messages.attachments.download');
    Route::post('portal/messages-broadcasts', [MessageController::class, 'broadcast'])->middleware('throttle:5,1')->name('messages.broadcast');
    Route::post('portal/messages-preferences', [MessageController::class, 'preferences'])->name('messages.preferences');
    Route::post('portal/messages/{conversation}/archive', [MessageController::class, 'archive'])->name('messages.archive');
    Route::post('portal/messages/{conversation}/messages/{message}/request-response', [MessageController::class, 'requestResponse'])->name('messages.request-response');
    Route::post('portal/action-requests/{actionRequest}/acknowledge', [MessageController::class, 'acknowledge'])->name('action-requests.acknowledge');

    Route::get('lessons/{lesson}/attendance', [AttendanceController::class, 'show'])->name('attendance.show');
    Route::post('lessons/{lesson}/attendance', [AttendanceController::class, 'store'])->name('attendance.store');

    Route::get('documents/my-work', MyDocumentWorkController::class)->name('documents.my-work');
    Route::get('documents/director-worklist', DirectorDocumentWorklistController::class)->name('documents.director-worklist');
    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{document}/versions', [DocumentVersionController::class, 'store'])->name('documents.versions.store');
    Route::get('documents/{document}/versions/{version}/download', [DocumentVersionController::class, 'download'])
        ->middleware('signed')
        ->name('documents.versions.download');
    Route::post('documents/{document}/submit', [DocumentApprovalController::class, 'submit'])->name('documents.submit');
    Route::post('approval-requests/{approvalRequest}/decide', [DocumentApprovalController::class, 'decide'])->name('documents.approvals.decide');

    // --- CMS (Pages / Posts / Media) — admin/director/editor/academic-manager
    // only; see CmsPageController's docblock for the exact role split.
    // Publishes docs/02 §5.1 + CLAUDE.md Phase 1's "draft → preview →
    // publish, and revert to a version" requirement, previously unbuilt.
    Route::get('portal/cms/pages', [CmsPageController::class, 'index'])->name('cms.pages.index');
    Route::get('portal/cms/pages/create', [CmsPageController::class, 'create'])->name('cms.pages.create');
    Route::post('portal/cms/pages', [CmsPageController::class, 'store'])->name('cms.pages.store');
    Route::get('portal/cms/pages/{page}/edit', [CmsPageController::class, 'edit'])->name('cms.pages.edit');
    Route::get('portal/cms/pages/{page}/preview', [CmsPageController::class, 'preview'])->name('cms.pages.preview');
    Route::put('portal/cms/pages/{page}', [CmsPageController::class, 'update'])->name('cms.pages.update');
    Route::post('portal/cms/pages/{page}/publish', [CmsPageController::class, 'publish'])->name('cms.pages.publish');
    Route::post('portal/cms/pages/{page}/unpublish', [CmsPageController::class, 'unpublish'])->name('cms.pages.unpublish');
    Route::post('portal/cms/pages/{page}/revisions/{revision}/restore', [CmsPageController::class, 'restoreRevision'])->name('cms.pages.revisions.restore');

    Route::get('portal/cms/posts', [CmsPostController::class, 'index'])->name('cms.posts.index');
    Route::get('portal/cms/posts/create', [CmsPostController::class, 'create'])->name('cms.posts.create');
    Route::post('portal/cms/posts', [CmsPostController::class, 'store'])->name('cms.posts.store');
    Route::get('portal/cms/posts/{post}/edit', [CmsPostController::class, 'edit'])->name('cms.posts.edit');
    Route::get('portal/cms/posts/{post}/preview', [CmsPostController::class, 'preview'])->name('cms.posts.preview');
    Route::put('portal/cms/posts/{post}', [CmsPostController::class, 'update'])->name('cms.posts.update');
    Route::post('portal/cms/posts/{post}/publish', [CmsPostController::class, 'publish'])->name('cms.posts.publish');
    Route::post('portal/cms/posts/{post}/unpublish', [CmsPostController::class, 'unpublish'])->name('cms.posts.unpublish');
    Route::post('portal/cms/posts/{post}/revisions/{revision}/restore', [CmsPostController::class, 'restoreRevision'])->name('cms.posts.revisions.restore');

    Route::get('portal/cms/media', [CmsMediaController::class, 'index'])->name('cms.media.index');
    Route::post('portal/cms/media', [CmsMediaController::class, 'store'])->name('cms.media.store');
    // --- end CMS ---

    // --- Teachers (admin/director/editor/academic-manager) — a dedicated
    // block separate from generic CMS pages, per explicit request: each
    // teacher gets a real public page plus a homepage carousel. ---
    Route::get('portal/teachers', [TeacherController::class, 'index'])->name('teachers.index');
    Route::get('portal/teachers/create', [TeacherController::class, 'create'])->name('teachers.create');
    Route::post('portal/teachers', [TeacherController::class, 'store'])->name('teachers.store');
    Route::get('portal/teachers/{teacher}/edit', [TeacherController::class, 'edit'])->name('teachers.edit');
    Route::put('portal/teachers/{teacher}', [TeacherController::class, 'update'])->name('teachers.update');
    Route::post('portal/teachers/{teacher}/publish', [TeacherController::class, 'publish'])->name('teachers.publish');
    Route::post('portal/teachers/{teacher}/unpublish', [TeacherController::class, 'unpublish'])->name('teachers.unpublish');
    // --- end Teachers ---

    // --- Academic structure (admin/director/academic-manager) — creates the
    // academic_years/school_classes/students rows that member invitations,
    // timetable and Assignments all depend on. Previously nothing could
    // create these outside a seeder; see AcademicStructureController's
    // docblock for the production incident this closes. ---
    Route::get('portal/academic-structure', [AcademicStructureController::class, 'index'])->name('academic-structure.index');
    Route::post('portal/academic-structure/academic-years', [AcademicStructureController::class, 'storeAcademicYear'])->name('academic-structure.academic-years.store');
    Route::post('portal/academic-structure/school-classes', [AcademicStructureController::class, 'storeSchoolClass'])->name('academic-structure.school-classes.store');
    Route::post('portal/academic-structure/school-classes/{schoolClass}/homeroom', [AcademicStructureController::class, 'assignHomeroom'])->name('academic-structure.school-classes.homeroom');
    Route::post('portal/academic-structure/students', [AcademicStructureController::class, 'storeStudent'])->name('academic-structure.students.store');
    // --- end Academic structure ---

    // --- Substitutions (admin/academic_manager/director) — the "teacher
    // workspace" piece the last audit found missing; lessons/attendance/
    // teacher_assignments were already real. Substitute teachers see their
    // assigned coverage on their own dashboard (DashboardController), no
    // separate route needed for that side. ---
    Route::get('portal/substitutions', [SubstitutionController::class, 'index'])->name('substitutions.index');
    Route::post('portal/substitutions/absences', [SubstitutionController::class, 'storeAbsence'])->name('substitutions.absences.store');
    Route::post('portal/substitutions/assign', [SubstitutionController::class, 'assign'])->name('substitutions.assign');
    Route::post('portal/substitutions/{substitutionAssignment}/cancel', [SubstitutionController::class, 'cancel'])->name('substitutions.cancel');
    Route::post('portal/substitutions/requests/{substitutionRequest}/decide', [SubstitutionController::class, 'decideRequest'])->name('substitutions.requests.decide');
    Route::get('portal/my-coverage', [CoverageController::class, 'index'])->name('coverage.index');
    Route::post('portal/my-coverage/requests', [CoverageController::class, 'storeRequest'])->name('coverage.requests.store');
    Route::post('portal/my-coverage/requests/{substitutionRequest}/withdraw', [CoverageController::class, 'withdrawRequest'])->name('coverage.requests.withdraw');
    Route::post('portal/substitutions/{substitutionAssignment}/handoff', [CoverageController::class, 'saveHandoff'])->name('coverage.handoff');
    // --- end Substitutions ---

    // --- Admissions pipeline (admin/academic_manager/director) — extends
    // the Phase 1 public lead form (AdmissionLeadController, /admissions/leads
    // POST above) into a real pipeline; the last audit found Admissions was
    // 0% built beyond that bare contact form. Deliberately under
    // portal/admissions-pipeline, not /admissions, so it never collides with
    // the public marketing page or the public lead POST endpoint. ---
    Route::get('portal/admissions-pipeline', [AdmissionsPipelineController::class, 'index'])->name('admissions-pipeline.index');
    Route::get('portal/admissions-pipeline/{admissionLead}', [AdmissionsPipelineController::class, 'show'])->name('admissions-pipeline.show');
    Route::post('portal/admissions-pipeline/{admissionLead}/advance-stage', [AdmissionsPipelineController::class, 'advanceStage'])->name('admissions-pipeline.advance-stage');
    Route::post('portal/admissions-pipeline/{admissionLead}/appointments', [AdmissionsPipelineController::class, 'storeAppointment'])->name('admissions-pipeline.appointments.store');
    Route::post('portal/admissions-pipeline/{admissionLead}/decision', [AdmissionsPipelineController::class, 'storeDecision'])->name('admissions-pipeline.decision.store');
    // --- end Admissions pipeline ---

    // --- Pickup & Consent (CLAUDE-PLATFORM-MODULES.md §7) — guardian-facing
    // authorized-pickup management under portal/pickup, and consent
    // forms/responses under portal/consents (published by admin/director,
    // responded to by guardians, roster viewed by admin/director). Added at
    // the end of this group so parallel agent edits above are easy to merge
    // around. ---
    Route::get('portal/pickup', [PickupController::class, 'index'])->name('pickup.index');
    Route::post('portal/pickup', [PickupController::class, 'store'])->name('pickup.store');
    Route::post('portal/pickup/{authorizedPickup}/remove', [PickupController::class, 'destroy'])->name('pickup.remove');
    Route::post('portal/pickup/codes', [PickupController::class, 'issueCode'])->middleware('throttle:10,1')->name('pickup.codes.issue');
    Route::post('portal/pickup/codes/{pickupCode}/cancel', [PickupController::class, 'cancelCode'])->name('pickup.codes.cancel');
    Route::get('portal/pickup-desk', [PickupController::class, 'verifyDesk'])->name('pickup.verify-desk');
    // Rate-limited: a code is short, so guessing must be impractical.
    Route::post('portal/pickup-desk/verify', [PickupController::class, 'verify'])->middleware('throttle:10,1')->name('pickup.verify');
    Route::post('portal/pickup-desk/override', [PickupController::class, 'override'])->name('pickup.override');

    Route::get('portal/consents', [ConsentController::class, 'index'])->name('consents.index');
    Route::post('portal/consents', [ConsentController::class, 'store'])->name('consents.store');
    Route::get('portal/consents/{consentForm}/roster', [ConsentController::class, 'roster'])->name('consents.roster');
    Route::post('portal/consents/{consentForm}/respond', [ConsentController::class, 'respond'])->name('consents.respond');
    // --- end Pickup & Consent ---

    // --- Assessment & Gradebook (CLAUDE-PLATFORM-MODULES.md §6) — school
    // grading setup (periods/scales/categories) for management roles, the
    // teacher gradebook, and the per-student progress view. ---
    Route::get('portal/assessment-settings', [AssessmentSettingsController::class, 'index'])->name('assessment-settings.index');
    Route::post('portal/assessment-settings/periods', [AssessmentSettingsController::class, 'storePeriod'])->name('assessment-settings.periods.store');
    Route::post('portal/assessment-settings/periods/{assessmentPeriod}/close', [AssessmentSettingsController::class, 'closePeriod'])->name('assessment-settings.periods.close');
    Route::post('portal/assessment-settings/periods/{assessmentPeriod}/reopen', [AssessmentSettingsController::class, 'reopenPeriod'])->name('assessment-settings.periods.reopen');
    Route::post('portal/assessment-settings/scales', [AssessmentSettingsController::class, 'storeScale'])->name('assessment-settings.scales.store');
    Route::post('portal/assessment-settings/categories', [AssessmentSettingsController::class, 'storeCategory'])->name('assessment-settings.categories.store');

    Route::get('portal/gradebook', [GradebookController::class, 'index'])->name('gradebook.index');
    Route::post('portal/gradebook/assessments', [GradebookController::class, 'store'])->name('gradebook.store');
    Route::get('portal/gradebook/assessments/{assessment}', [GradebookController::class, 'show'])->name('gradebook.show');
    Route::post('portal/gradebook/assessments/{assessment}/results', [GradebookController::class, 'recordResults'])->name('gradebook.results.store');
    Route::post('portal/gradebook/assessments/{assessment}/publish', [GradebookController::class, 'publish'])->name('gradebook.publish');

    Route::get('portal/competencies', [CompetencyController::class, 'index'])->name('competencies.index');
    Route::post('portal/competencies', [CompetencyController::class, 'store'])->name('competencies.store');
    Route::post('portal/competencies/observations', [CompetencyController::class, 'observe'])->name('competencies.observe');

    Route::get('portal/my-progress', [StudentProgressController::class, 'mine'])->name('progress.mine');
    Route::get('portal/students/{student}/progress', [StudentProgressController::class, 'show'])->name('progress.show');

    Route::get('portal/report-cards', [ReportCardController::class, 'index'])->name('report-cards.index');
    Route::post('portal/report-cards/generate', [ReportCardController::class, 'generate'])->name('report-cards.generate');
    Route::get('portal/report-cards/{reportCard}', [ReportCardController::class, 'show'])->name('report-cards.show');
    Route::post('portal/report-cards/{reportCard}/lines/{line}', [ReportCardController::class, 'saveLine'])->name('report-cards.lines.save');
    Route::post('portal/report-cards/{reportCard}/homeroom-submit', [ReportCardController::class, 'homeroomSubmit'])->name('report-cards.homeroom-submit');
    Route::post('portal/report-cards/{reportCard}/approve', [ReportCardController::class, 'approve'])->name('report-cards.approve');
    Route::post('portal/report-cards/{reportCard}/publish', [ReportCardController::class, 'publish'])->name('report-cards.publish');
    Route::post('portal/report-cards/{reportCard}/return', [ReportCardController::class, 'returnForChanges'])->name('report-cards.return');
    Route::post('portal/report-cards/{reportCard}/revise', [ReportCardController::class, 'revise'])->name('report-cards.revise');
    Route::get('portal/students/{student}/report-cards', [ReportCardController::class, 'forStudent'])->name('report-cards.student');
    Route::get('portal/report-card-documents/{publication}', [ReportCardController::class, 'showPublication'])->name('report-cards.document');
    Route::get('portal/report-card-documents/{publication}/pdf', [ReportCardController::class, 'downloadPublicationPdf'])->middleware('throttle:20,1')->name('report-cards.document.pdf');
    // --- end Assessment & Gradebook ---

    // --- Health record (CLAUDE-PLATFORM-MODULES.md §8) — access decided by
    // HealthAccess (explicit capability grants + 2FA, guardian link,
    // reasoned emergency access, alerts-only for the student's teachers). ---
    Route::get('portal/health', [HealthController::class, 'index'])->name('health.index');
    Route::get('portal/health/permissions', [HealthController::class, 'permissions'])->name('health.permissions');
    Route::post('portal/health/permissions', [HealthController::class, 'grant'])->middleware('reauth')->name('health.permissions.grant');
    Route::post('portal/health/permissions/{grant}/revoke', [HealthController::class, 'revoke'])->middleware('reauth')->name('health.permissions.revoke');
    Route::post('portal/health/emergency-accesses/{emergencyAccess}/review', [HealthController::class, 'reviewEmergency'])->name('health.emergency.review');
    Route::get('portal/students/{student}/health', [HealthController::class, 'show'])->name('health.show');
    Route::get('portal/students/{student}/health/access-log', [HealthController::class, 'accessLog'])->name('health.access-log');
    Route::post('portal/students/{student}/health/profile', [HealthController::class, 'saveProfile'])->name('health.profile.save');
    Route::post('portal/students/{student}/health/conditions', [HealthController::class, 'storeCondition'])->name('health.conditions.store');
    Route::post('portal/students/{student}/health/medications', [HealthController::class, 'storeMedication'])->name('health.medications.store');
    Route::post('portal/students/{student}/health/contacts', [HealthController::class, 'storeContact'])->name('health.contacts.store');
    Route::post('portal/students/{student}/health/care-plans', [HealthController::class, 'storeCarePlan'])->name('health.care-plans.store');
    Route::get('portal/students/{student}/health/care-plans/{document}/download', [HealthController::class, 'downloadCarePlan'])
        ->middleware('signed')
        ->name('health.care-plans.download');
    Route::post('portal/students/{student}/health/incidents', [HealthController::class, 'storeIncident'])->name('health.incidents.store');
    Route::post('portal/students/{student}/health/emergency-access', [HealthController::class, 'requestEmergency'])->name('health.emergency.request');
    Route::post('portal/students/{student}/health/{type}/{id}/remove', [HealthController::class, 'destroyItem'])
        ->whereIn('type', ['conditions', 'medications', 'contacts', 'care-plans'])
        ->whereNumber('id')
        ->name('health.items.remove');
    // --- end Health record ---

    // --- Parent–teacher meetings (ფაზა C appointments) ---
    Route::get('portal/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::post('portal/appointments/slots', [AppointmentController::class, 'storeSlot'])->name('appointments.slots.store');
    Route::post('portal/appointments/slots/{slot}/cancel', [AppointmentController::class, 'cancelSlot'])->name('appointments.slots.cancel');
    Route::post('portal/appointments/slots/{slot}/book', [AppointmentController::class, 'book'])->name('appointments.book');
    Route::post('portal/appointments/bookings/{booking}/cancel', [AppointmentController::class, 'cancelBooking'])->name('appointments.bookings.cancel');
    // --- end Parent–teacher meetings ---

    // --- Operations (CLAUDE-PLATFORM-MODULES.md §11), each module behind
    // its own feature entitlement. ---
    Route::get('portal/services', [ServicesController::class, 'index'])->name('services.index');
    Route::get('portal/director', SchoolPulseController::class)->name('director.pulse');

    Route::middleware('feature:clubs')->group(function () {
        Route::get('portal/clubs', [ClubController::class, 'index'])->name('clubs.index');
        Route::post('portal/clubs', [ClubController::class, 'store'])->name('clubs.store');
        Route::post('portal/clubs/{club}/capacity', [ClubController::class, 'updateCapacity'])->name('clubs.capacity');
        Route::get('portal/clubs/{club}/roster', [ClubController::class, 'roster'])->name('clubs.roster');
        Route::post('portal/clubs/{club}/join', [ClubController::class, 'join'])->name('clubs.join');
        Route::post('portal/clubs/{club}/leave', [ClubController::class, 'leave'])->name('clubs.leave');
    });

    Route::middleware('feature:transport')->group(function () {
        Route::get('portal/transport', [TransportController::class, 'index'])->name('transport.index');
        Route::post('portal/transport/routes', [TransportController::class, 'storeRoute'])->name('transport.routes.store');
        Route::post('portal/transport/routes/{transportRoute}/stops', [TransportController::class, 'storeStop'])->name('transport.stops.store');
        Route::post('portal/transport/routes/{transportRoute}/assign', [TransportController::class, 'assign'])->name('transport.assign');
        Route::post('portal/transport/routes/{transportRoute}/status', [TransportController::class, 'reportStatus'])->name('transport.status');
    });

    Route::middleware('feature:meals')->group(function () {
        Route::get('portal/meals', [MealController::class, 'index'])->name('meals.index');
        Route::post('portal/meals/menus', [MealController::class, 'saveDraft'])->name('meals.menus.save');
        Route::post('portal/meals/menus/{menu}/publish', [MealController::class, 'publish'])->name('meals.menus.publish');
        Route::post('portal/meals/students/{student}/dietary', [MealController::class, 'saveDietary'])->name('meals.dietary');
        Route::post('portal/meals/students/{student}/opt-out', [MealController::class, 'optOut'])->name('meals.opt-out');
    });
    // --- end Operations ---

    // --- Finance (CLAUDE-PLATFORM-MODULES.md §12) — SANDBOX ONLY until a
    // real provider contract and accountant UAT. ---
    Route::middleware('feature:finance')->group(function () {
        Route::get('portal/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::post('portal/finance/invoices', [FinanceController::class, 'storeInvoice'])->name('finance.invoices.store');
        Route::get('portal/finance/invoices/{invoice}', [FinanceController::class, 'showInvoice'])->name('finance.invoices.show');
        Route::post('portal/finance/invoices/{invoice}/void', [FinanceController::class, 'voidInvoice'])->name('finance.invoices.void');
        Route::post('portal/finance/invoices/{invoice}/pay', [FinanceController::class, 'pay'])->middleware('throttle:10,1')->name('finance.invoices.pay');
        Route::get('portal/finance/return/{intent}', [FinanceController::class, 'returnPage'])->name('finance.return');
        Route::post('portal/finance/payments/{payment}/allocate', [FinanceController::class, 'allocate'])->name('finance.payments.allocate');
        Route::post('portal/finance/payments/{payment}/refund', [FinanceController::class, 'refund'])->name('finance.payments.refund');
        Route::get('portal/finance/reconciliation', [FinanceController::class, 'reconciliation'])->name('finance.reconciliation');
        Route::post('portal/finance/clubs/{club}/invoice', [FinanceController::class, 'invoiceFromClub'])->name('finance.clubs.invoice');

        Route::get('portal/finance/sandbox/{intent}', [FinanceSandboxController::class, 'checkout'])->name('finance.sandbox.checkout');
        Route::post('portal/finance/sandbox/{intent}/simulate', [FinanceSandboxController::class, 'simulate'])->name('finance.sandbox.simulate');
        Route::post('portal/finance/sandbox/refunds/{refund}/confirm', [FinanceSandboxController::class, 'confirmRefund'])->name('finance.sandbox.refunds.confirm');
    });
    // --- end Finance ---

    // --- Support access, school side (CLAUDE-PLATFORM-MODULES.md §14) ---
    Route::get('portal/support-access', [SupportAccessController::class, 'index'])->name('support-access.index');
    Route::post('portal/support-access/{supportRequest}', [SupportAccessController::class, 'decide'])->middleware('reauth')->name('support-access.decide');

    // --- Member management (admin/director) — added at the end of this
    // group so parallel agent edits above are easy to merge around. ---
    Route::get('portal/members', [MemberController::class, 'index'])->name('members.index');
    Route::post('portal/members/invite', [MemberController::class, 'store'])->middleware('reauth')->name('members.invite');
    Route::post('portal/members/{membership}/revoke', [MemberController::class, 'revoke'])->middleware('reauth')->name('members.revoke');
    Route::delete('portal/members/invitations/{invitation}', [MemberController::class, 'cancelInvitation'])->name('members.invitations.cancel');
    Route::post('portal/members/enrollment-requests/{enrollmentRequest}/decide', [MemberController::class, 'decideEnrollment'])->name('members.enrollment-requests.decide');
});

// --- Platform administration (CLAUDE-PLATFORM-MODULES.md §14) — platform
// operators only (separate model + 2FA), 404 for everyone else. ---
Route::middleware(['auth', 'verified', 'platform'])->prefix('platform-admin')->name('platform.')->group(function () {
    Route::get('/', [PlatformAdminController::class, 'index'])->name('index');
    Route::post('tenants', [PlatformAdminController::class, 'storeTenant'])->name('tenants.store');
    Route::get('tenants/{tenant}', [PlatformAdminController::class, 'show'])->name('tenants.show');
    Route::post('tenants/{tenant}/domains', [PlatformAdminController::class, 'addDomain'])->name('tenants.domains.store');
    Route::post('tenants/{tenant}/domains/{domain}/verify', [PlatformAdminController::class, 'verifyDomain'])->name('tenants.domains.verify');
    Route::post('tenants/{tenant}/onboarding/{step}', [PlatformAdminController::class, 'onboarding'])->name('tenants.onboarding');
    Route::post('tenants/{tenant}/features', [PlatformAdminController::class, 'setFeature'])->name('tenants.features');
    Route::post('tenants/{tenant}/subscription', [PlatformAdminController::class, 'assignPlan'])->name('tenants.subscription');
    Route::post('tenants/{tenant}/support-requests', [PlatformAdminController::class, 'requestSupport'])->name('tenants.support.request');
    Route::get('tenants/{tenant}/support-view', [PlatformAdminController::class, 'supportView'])->name('tenants.support.view');
    Route::post('plans', [PlatformAdminController::class, 'storePlan'])->name('plans.store');
});

require __DIR__.'/settings.php';

// Catch-all CMS page route — must stay last so it never shadows a more
// specific route above (sitemap.xml, news, library, dashboard, etc.).
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('page.show');

// Unmatched URLs (e.g. percent-encoded Georgian WordPress slugs) still pass
// through the web middleware, so RedirectLegacyUrls can 301 them.
Route::fallback(fn () => abort(404));
