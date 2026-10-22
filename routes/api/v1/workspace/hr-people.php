<?php

use App\Http\Controllers\Api\V1\Hr\AbsenceController;
use App\Http\Controllers\Api\V1\Hr\ClockController;
use App\Http\Controllers\Api\V1\Hr\ContractController;
use App\Http\Controllers\Api\V1\Hr\DisciplinaryActionController;
use App\Http\Controllers\Api\V1\Hr\EmployeeController;
use App\Http\Controllers\Api\V1\Hr\EmployeeRecordController;
use App\Http\Controllers\Api\V1\Hr\EmploymentHistoryController;
use App\Http\Controllers\Api\V1\Hr\HolidayController;
use App\Http\Controllers\Api\V1\Hr\HrAnalyticsController;
use App\Http\Controllers\Api\V1\Hr\HrDashboardController;
use App\Http\Controllers\Api\V1\Hr\LeaveRequestController;
use App\Http\Controllers\Api\V1\Hr\LeaveReviewController;
use App\Http\Controllers\Api\V1\Hr\LeaveTypeController;
use App\Http\Controllers\Api\V1\Hr\OrgChartController;
use App\Http\Controllers\Api\V1\Hr\ShiftController;
use App\Http\Controllers\Api\V1\Hr\TimeEntryController;
use App\Http\Controllers\Hr\ContractController as WebContractController;
use App\Http\Controllers\Hr\DisciplinaryActionController as WebDisciplinaryActionController;
use App\Http\Controllers\Hr\EmployeeRecordController as WebEmployeeRecordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HR — people, time and leave
|--------------------------------------------------------------------------
|
| The self-service half (clock, leave, holidays, your own file) and HR's
| employee files, mirroring the web app's /w/{workspace}/hr routes. Nested
| {employmentHistory}, {contract}, {record}, {disciplinaryAction} and {absence}
| are scoped to the {employee} in the path by the group's scopeBindings().
|
| File downloads route straight to the web controllers: they only authorize
| and stream, which works the same under a bearer token.
|
*/

Route::prefix('hr')->name('hr.')->group(function () {
    Route::get('/', [HrDashboardController::class, 'index'])->name('index');
    Route::get('dashboard', [HrAnalyticsController::class, 'index'])->name('dashboard');
    Route::get('clock', [ClockController::class, 'show'])->name('clock.show');
    Route::get('org-chart', [OrgChartController::class, 'index'])->name('org-chart');

    Route::get('me', [EmployeeController::class, 'me'])->name('me');
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');

    Route::post('employees/{employee}/history', [EmploymentHistoryController::class, 'store'])
        ->name('employees.history.store');
    Route::delete('employees/{employee}/history/{employmentHistory}', [EmploymentHistoryController::class, 'destroy'])
        ->name('employees.history.destroy');

    Route::post('employees/{employee}/contracts', [ContractController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('employees.contracts.store');
    // POST as well as PATCH: a replacement file needs multipart, which PHP
    // only parses on POST. The web app spoofs the method; a client need not.
    Route::match(['patch', 'post'], 'employees/{employee}/contracts/{contract}', [ContractController::class, 'update'])
        ->middleware('throttle:uploads')
        ->name('employees.contracts.update');
    Route::delete('employees/{employee}/contracts/{contract}', [ContractController::class, 'destroy'])
        ->name('employees.contracts.destroy');
    Route::get('employees/{employee}/contracts/{contract}/file', [WebContractController::class, 'download'])
        ->name('employees.contracts.download');

    Route::post('employees/{employee}/records', [EmployeeRecordController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('employees.records.store');
    Route::delete('employees/{employee}/records/{record}', [EmployeeRecordController::class, 'destroy'])
        ->name('employees.records.destroy');
    Route::get('employees/{employee}/records/{record}/file', [WebEmployeeRecordController::class, 'download'])
        ->name('employees.records.download');

    Route::post('employees/{employee}/disciplinary', [DisciplinaryActionController::class, 'store'])
        ->middleware('throttle:uploads')
        ->name('employees.disciplinary.store');
    Route::delete('employees/{employee}/disciplinary/{disciplinaryAction}', [DisciplinaryActionController::class, 'destroy'])
        ->name('employees.disciplinary.destroy');
    Route::get('employees/{employee}/disciplinary/{disciplinaryAction}/file', [WebDisciplinaryActionController::class, 'download'])
        ->name('employees.disciplinary.download');

    Route::post('employees/{employee}/absences', [AbsenceController::class, 'store'])
        ->name('employees.absences.store');
    Route::delete('employees/{employee}/absences/{absence}', [AbsenceController::class, 'destroy'])
        ->name('employees.absences.destroy');

    // Leave. Approve and reject stay separate so a repeated tap can never
    // flip a decision — see LeaveReviewController.
    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveRequestController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('leave.store');
    Route::post('leave/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');
    Route::post('leave/{leaveRequest}/approve', [LeaveReviewController::class, 'approve'])->name('leave.approve');
    Route::post('leave/{leaveRequest}/reject', [LeaveReviewController::class, 'reject'])->name('leave.reject');

    Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
    Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave-types.destroy');

    // The geotagged time clock, and the timesheets it fills.
    Route::get('time', [TimeEntryController::class, 'index'])->name('time.index');
    Route::post('time/clock-in', [ClockController::class, 'in'])
        ->middleware('throttle:20,1')
        ->name('clock.in');
    Route::post('time/clock-out', [ClockController::class, 'out'])
        ->middleware('throttle:20,1')
        ->name('clock.out');
    Route::patch('time/{timeEntry}', [TimeEntryController::class, 'update'])->name('time.update');
    Route::delete('time/{timeEntry}', [TimeEntryController::class, 'destroy'])->name('time.destroy');

    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('shifts', [ShiftController::class, 'store'])->name('shifts.store');
    Route::patch('shifts/{shift}', [ShiftController::class, 'update'])->name('shifts.update');
    Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])->name('shifts.destroy');

    Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
    Route::patch('holidays/{holiday}', [HolidayController::class, 'update'])->name('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
});
