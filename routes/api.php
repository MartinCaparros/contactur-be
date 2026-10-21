<?php

use App\Http\Controllers\AccessController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminAccessController;
use App\Http\Controllers\AdminAuditLogController;
use App\Http\Controllers\AdminDocumentController;
use App\Http\Controllers\AdminMetricsController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AdminReferenceController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContactRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\SupportInquiryController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn (): JsonResponse => response()->json([
    'status' => 'ok',
]))->name('health');

// Público (R23): sin JWT; rate limit por IP y honeypot en el controller.
Route::post('/support-inquiries', [SupportInquiryController::class, 'store'])
    ->middleware('throttle:support.inquiries')
    ->name('support-inquiries.store');

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:auth.register')
        ->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth.login')
        ->name('auth.login');
    Route::post('/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth.recovery')
        ->name('auth.forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth.recovery')
        ->name('auth.reset-password');
    Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->name('auth.email.verify');

    Route::middleware('auth.access')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/email/resend', [AuthController::class, 'resendEmail'])
            ->middleware(['account.active', 'throttle:auth.resend'])
            ->name('auth.email.resend');
    });
});

Route::middleware('auth.access')->group(function (): void {
    Route::get('/me', [AccountController::class, 'show'])->name('me.show');
    Route::get('/profiles/{profile}/access', [AccessController::class, 'show'])
        ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
        ->name('profiles.access.show');

    Route::middleware('account.active')->group(function (): void {
        Route::patch('/me', [AccountController::class, 'update'])->name('me.update');
        Route::post('/me/change-email', [AccountController::class, 'changeEmail'])->name('me.change-email');
        Route::post('/me/change-password', [AccountController::class, 'changePassword'])->name('me.change-password');
        // Propia en lugar de Broadcast::routes(): contrato con sobre `data` y errores del proyecto.
        Route::post('/broadcasting/auth', [RealtimeController::class, 'authorizeChannel'])
            ->middleware('throttle:realtime.auth')
            ->name('broadcasting.auth');

        Route::get('/catalogs', [CatalogController::class, 'index'])->name('catalogs.index');
        Route::post('/profiles', [ProfileController::class, 'store'])->name('profiles.store');
        Route::get('/profiles/{profile}', [ProfileController::class, 'show'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.show');
        Route::patch('/profiles/{profile}', [ProfileController::class, 'update'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.update');
        Route::post('/profiles/{profile}/complete', [ProfileController::class, 'complete'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.complete');
        Route::post('/profiles/{profile}/terms', [ProfileController::class, 'acceptTerms'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.terms');
        Route::put('/profiles/{profile}/cities', [ProfileController::class, 'replaceCities'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.cities.replace');
        Route::get('/profiles/{profile}/availability', [AvailabilityController::class, 'show'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.availability.show');
        Route::put('/profiles/{profile}/availability', [AvailabilityController::class, 'update'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.availability.replace');

        Route::get('/profiles/{profile}/search', [DirectoryController::class, 'search'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.search');
        Route::get('/profiles/{profile}/directory/{target}', [DirectoryController::class, 'show'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'target' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.directory.show');
        Route::get('/profiles/{profile}/directory/{target}/availability', [DirectoryController::class, 'availability'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'target' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.directory.availability');

        Route::post('/profiles/{profile}/contact-requests', [ContactRequestController::class, 'store'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.contact-requests.store');
        Route::get('/profiles/{profile}/contact-requests', [ContactRequestController::class, 'index'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.contact-requests.index');
        Route::get('/profiles/{profile}/contact-requests/{request}', [ContactRequestController::class, 'show'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.show');
        Route::post('/profiles/{profile}/contact-requests/{request}/cancel', [ContactRequestController::class, 'cancel'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.cancel');
        Route::post('/profiles/{profile}/contact-requests/{request}/accept', [ContactRequestController::class, 'accept'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.accept');
        Route::post('/profiles/{profile}/contact-requests/{request}/reject', [ContactRequestController::class, 'reject'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.reject');
        Route::get('/profiles/{profile}/contact-requests/{request}/contact', [ContactRequestController::class, 'contact'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.contact');

        Route::post('/profiles/{profile}/contact-requests/{request}/references', [ReferenceController::class, 'store'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'request' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.contact-requests.references.store');
        Route::get('/profiles/{profile}/references', [ReferenceController::class, 'index'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.references.index');
        Route::patch('/profiles/{profile}/references/{reference}/visibility', [ReferenceController::class, 'updateVisibility'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'reference' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.references.visibility.update');

        // T21: mismo grupo que notificaciones (cuenta activa); el resto es ownership (D21).
        Route::get('/profiles/{profile}/dashboard', [DashboardController::class, 'show'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.dashboard.show');

        Route::get('/profiles/{profile}/notifications', [NotificationController::class, 'index'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.notifications.index');
        Route::post('/profiles/{profile}/notifications/{notification}/read', [NotificationController::class, 'read'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'notification' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.notifications.read');

        Route::post('/profiles/{profile}/avatar', [MediaController::class, 'avatar'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.avatar.store');
        Route::post('/profiles/{profile}/gallery', [MediaController::class, 'gallery'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.gallery.store');
        Route::delete('/profiles/{profile}/gallery/{gallery}', [MediaController::class, 'deleteGallery'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'gallery' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.gallery.destroy');

        Route::get('/profiles/{profile}/documents', [DocumentController::class, 'index'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.documents.index');
        Route::post('/profiles/{profile}/documents', [DocumentController::class, 'store'])
            ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('profiles.documents.store');
        Route::get('/profiles/{profile}/documents/{document}/download', [DocumentController::class, 'download'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'document' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.documents.download');
        Route::delete('/profiles/{profile}/documents/{document}', [DocumentController::class, 'destroy'])
            ->where([
                'profile' => '[0-9A-HJKMNP-TV-Z]{26}',
                'document' => '[0-9A-HJKMNP-TV-Z]{26}',
            ])->name('profiles.documents.destroy');

        Route::middleware('admin.active')->prefix('admin')->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])
                ->name('admin.users.index');
            Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])
                ->where('user', '[0-9A-HJKMNP-TV-Z]{26}')
                ->name('admin.users.status.update');
            Route::get('/profiles', [AdminProfileController::class, 'index'])
                ->name('admin.profiles.index');
            Route::patch('/profiles/{profile}/status', [AdminProfileController::class, 'updateStatus'])
                ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
                ->name('admin.profiles.status.update');
            Route::put('/profiles/{profile}/access', [AdminAccessController::class, 'update'])
                ->where('profile', '[0-9A-HJKMNP-TV-Z]{26}')
                ->name('admin.profiles.access.update');
            Route::get('/metrics', [AdminMetricsController::class, 'index'])
                ->name('admin.metrics.index');
            Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
                ->name('admin.audit-logs.index');
            Route::get('/documents', [AdminDocumentController::class, 'index'])
                ->name('admin.documents.index');
            Route::post('/documents/{document}/review', [AdminDocumentController::class, 'review'])
                ->where('document', '[0-9A-HJKMNP-TV-Z]{26}')
                ->name('admin.documents.review');
            Route::get('/references', [AdminReferenceController::class, 'index'])
                ->name('admin.references.index');
            Route::post('/references/{reference}/moderate', [AdminReferenceController::class, 'moderate'])
                ->where('reference', '[0-9A-HJKMNP-TV-Z]{26}')
                ->name('admin.references.moderate');
        });
    });
});
