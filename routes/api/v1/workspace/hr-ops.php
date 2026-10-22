<?php

use App\Http\Controllers\Api\V1\Hr\AnnouncementController;
use App\Http\Controllers\Api\V1\Hr\AttendanceRequestController;
use App\Http\Controllers\Api\V1\Hr\ChecklistController;
use App\Http\Controllers\Api\V1\Hr\ChecklistTemplateController;
use App\Http\Controllers\Api\V1\Hr\CompanyPolicyController;
use App\Http\Controllers\Api\V1\Hr\DocumentController;
use App\Http\Controllers\Api\V1\Hr\EquipmentAssignmentController;
use App\Http\Controllers\Api\V1\Hr\EquipmentCategoryController;
use App\Http\Controllers\Api\V1\Hr\EquipmentController;
use App\Http\Controllers\Api\V1\Hr\EquipmentRequestController;
use App\Http\Controllers\Api\V1\Hr\HrRequestController;
use App\Http\Controllers\Api\V1\Hr\HrSettingsController;
use App\Http\Controllers\Api\V1\Hr\ResignationController;
use App\Http\Controllers\Api\V1\Hr\ShiftRequestController;
use App\Http\Controllers\Api\V1\Hr\WorkTypeRequestController;
use App\Http\Controllers\Hr\CompanyPolicyController as WebCompanyPolicyController;
use App\Http\Controllers\Hr\DocumentController as WebDocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HR — operations and requests
|--------------------------------------------------------------------------
|
| Announcements, onboarding and offboarding, document requests, the company
| handbook, equipment, shift / work-type / attendance requests, resignations,
| and HR settings: the mobile mirror of the same routes under the web app's
| /w/{workspace}/hr, with the same throttles. Nested ids resolve through the
| workspace (scopeBindings), so another tenant's id 404s at the router.
|
| The two file downloads route straight to the web controllers: they only
| stream, and work the same under a bearer token.
|
*/

Route::prefix('hr')->name('hr.')->group(function () {
    Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::patch('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

    Route::get('onboarding', [ChecklistController::class, 'onboarding'])->name('onboarding.index');
    Route::get('offboarding', [ChecklistController::class, 'offboarding'])->name('offboarding.index');
    Route::post('checklists', [ChecklistController::class, 'store'])->name('checklists.store');
    Route::post('checklists/{employeeChecklist}/complete', [ChecklistController::class, 'complete'])->name('checklists.complete');
    Route::post('checklists/{employeeChecklist}/cancel', [ChecklistController::class, 'cancel'])->name('checklists.cancel');
    Route::post('checklist-tasks/{employeeChecklistTask}/toggle', [ChecklistController::class, 'toggle'])->name('checklists.tasks.toggle');
    Route::post('checklist-templates', [ChecklistTemplateController::class, 'store'])->name('checklist-templates.store');
    Route::patch('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'update'])->name('checklist-templates.update');
    Route::delete('checklist-templates/{checklistTemplate}', [ChecklistTemplateController::class, 'destroy'])->name('checklist-templates.destroy');

    Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('documents/submissions/{documentSubmission}/upload', [DocumentController::class, 'upload'])->middleware('throttle:uploads')->name('documents.upload');
    Route::post('documents/submissions/{documentSubmission}/approve', [DocumentController::class, 'approve'])->name('documents.approve');
    Route::post('documents/submissions/{documentSubmission}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
    Route::get('documents/submissions/{documentSubmission}/file', [WebDocumentController::class, 'download'])->name('documents.download');
    Route::delete('documents/{documentRequest}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('policies', [CompanyPolicyController::class, 'index'])->name('policies.index');
    Route::post('policies', [CompanyPolicyController::class, 'store'])->middleware('throttle:uploads')->name('policies.store');
    // POST, as on the web: the update is multipart when a file is attached.
    Route::post('policies/{companyPolicy}', [CompanyPolicyController::class, 'update'])->middleware('throttle:uploads')->name('policies.update');
    Route::delete('policies/{companyPolicy}', [CompanyPolicyController::class, 'destroy'])->name('policies.destroy');
    Route::post('policies/{companyPolicy}/acknowledge', [CompanyPolicyController::class, 'acknowledge'])->name('policies.acknowledge');
    Route::get('policies/{companyPolicy}/file', [WebCompanyPolicyController::class, 'download'])->name('policies.download');

    Route::get('my-equipment', [EquipmentRequestController::class, 'mine'])->name('my-equipment');
    Route::post('equipment-requests', [EquipmentRequestController::class, 'store'])->middleware('throttle:30,1')->name('equipment-requests.store');
    Route::post('equipment-requests/{equipmentRequest}/cancel', [EquipmentRequestController::class, 'cancel'])->name('equipment-requests.cancel');
    Route::post('equipment-requests/{equipmentRequest}/approve', [EquipmentRequestController::class, 'approve'])->name('equipment-requests.approve');
    Route::post('equipment-requests/{equipmentRequest}/reject', [EquipmentRequestController::class, 'reject'])->name('equipment-requests.reject');
    Route::post('equipment-assignments/{equipmentAssignment}/request-return', [EquipmentAssignmentController::class, 'requestReturn'])->name('equipment-assignments.request-return');
    Route::post('equipment-assignments/{equipmentAssignment}/return', [EquipmentAssignmentController::class, 'takeBack'])->name('equipment-assignments.return');

    Route::get('equipment', [EquipmentController::class, 'index'])->name('equipment.index');
    Route::post('equipment', [EquipmentController::class, 'store'])->name('equipment.store');
    Route::patch('equipment/{equipment}', [EquipmentController::class, 'update'])->name('equipment.update');
    Route::delete('equipment/{equipment}', [EquipmentController::class, 'destroy'])->name('equipment.destroy');
    Route::post('equipment/{equipment}/assign', [EquipmentAssignmentController::class, 'store'])->name('equipment.assign');
    Route::post('equipment-categories', [EquipmentCategoryController::class, 'store'])->name('equipment-categories.store');
    Route::patch('equipment-categories/{equipmentCategory}', [EquipmentCategoryController::class, 'update'])->name('equipment-categories.update');
    Route::delete('equipment-categories/{equipmentCategory}', [EquipmentCategoryController::class, 'destroy'])->name('equipment-categories.destroy');

    Route::get('requests', [HrRequestController::class, 'index'])->name('requests.index');
    Route::post('requests/shift', [ShiftRequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.shift.store');
    Route::post('requests/shift/{shiftRequest}/cancel', [ShiftRequestController::class, 'cancel'])->name('requests.shift.cancel');
    Route::post('requests/shift/{shiftRequest}/approve', [ShiftRequestController::class, 'approve'])->name('requests.shift.approve');
    Route::post('requests/shift/{shiftRequest}/reject', [ShiftRequestController::class, 'reject'])->name('requests.shift.reject');
    Route::post('requests/work-type', [WorkTypeRequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.work-type.store');
    Route::post('requests/work-type/{workTypeRequest}/cancel', [WorkTypeRequestController::class, 'cancel'])->name('requests.work-type.cancel');
    Route::post('requests/work-type/{workTypeRequest}/approve', [WorkTypeRequestController::class, 'approve'])->name('requests.work-type.approve');
    Route::post('requests/work-type/{workTypeRequest}/reject', [WorkTypeRequestController::class, 'reject'])->name('requests.work-type.reject');
    Route::post('requests/attendance', [AttendanceRequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.attendance.store');
    Route::post('requests/attendance/{attendanceRequest}/cancel', [AttendanceRequestController::class, 'cancel'])->name('requests.attendance.cancel');
    Route::post('requests/attendance/{attendanceRequest}/approve', [AttendanceRequestController::class, 'approve'])->name('requests.attendance.approve');
    Route::post('requests/attendance/{attendanceRequest}/reject', [AttendanceRequestController::class, 'reject'])->name('requests.attendance.reject');

    Route::post('resignations', [ResignationController::class, 'store'])->middleware('throttle:10,1')->name('resignations.store');
    Route::post('resignations/{resignation}/withdraw', [ResignationController::class, 'withdraw'])->name('resignations.withdraw');
    Route::post('resignations/{resignation}/accept', [ResignationController::class, 'accept'])->name('resignations.accept');
    Route::post('resignations/{resignation}/reject', [ResignationController::class, 'reject'])->name('resignations.reject');

    Route::get('settings', [HrSettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings', [HrSettingsController::class, 'update'])->name('settings.update');
});
