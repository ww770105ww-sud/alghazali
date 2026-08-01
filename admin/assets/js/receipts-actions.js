$(document).ready(function () {
    // دالة إلغاء/عكس السند
    window.reverseVoucher = function (id) {
        Swal.fire({
            title: 'هل أنت متأكد من إلغاء السند؟',
            text: "سيتم تطبيق الإجراء بناءً على إعدادات النظام (إلغاء مباشر أو إنشاء سند عكسي).",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، إلغاء',
            cancelButtonText: 'تراجع',
            input: 'text',
            inputPlaceholder: 'أدخل سبب الإلغاء هنا...',
            inputValidator: (value) => {
                if (!value) {
                    return 'يجب إدخال سبب للإلغاء!'
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/reverse_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        reason: result.value,
                        csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
                    },
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            Swal.fire({
                                title: 'تمت العملية!',
                                text: 'تم إلغاء/عكس السند بنجاح.',
                                icon: 'success'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ!', res.message || 'فشل تنفيذ العملية.', 'error');
                        }
                    },
                    error: function (xhr) {
                        Swal.fire('خطأ!', 'حدث خطأ في الاتصال بالخادم.', 'error');
                    }
                });
            }
        });
    };

    // دالة حذف السند (للسندات غير المرحلة فقط)
    window.deleteVoucher = function (id) {
        Swal.fire({
            title: 'حذف السند نهائياً؟',
            text: "لا يمكن التراجع عن هذه العملية!",
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، احذف',
            cancelButtonText: 'تراجع'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/delete_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
                    },
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            location.reload();
                        } else {
                            Swal.fire('فشل الحذف!', res.message || 'خطأ غير معروف', 'error');
                        }
                    },
                    error: function (xhr) {
                        Swal.fire('خطأ!', 'حدث خطأ في الاتصال.', 'error');
                    }
                });
            }
        });
    };

    // تحديث دالة عرض تفاصيل السند لإظهار الروابط بين السند الأصلي والعكسي
    window.viewVoucher = function (id) {
        $.get('ajax/get_voucher_details.php', { id: id }, function (v) {
            let statusLabel = '';
            let statusClass = '';
            switch(v.status) {
                case 'draft': statusLabel = '🟡 مسودة'; statusClass = 'bg-warning text-dark'; break;
                case 'posted': statusLabel = '🟢 مرحل'; statusClass = 'bg-success'; break;
                case 'cancelled': statusLabel = '🔴 ملغي'; statusClass = 'bg-danger'; break;
                case 'reversed': statusLabel = '🟠 معكوس'; statusClass = 'bg-secondary'; break;
            }

            let reversalInfo = '';
            if (v.status === 'reversed' && v.reversal_voucher_id) {
                reversalInfo = `
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exchange-alt me-2"></i>هذا السند تم عكسه بالسند رقم: 
                        <a href="#" onclick="viewVoucher(${v.reversal_voucher_id}); return false;" class="fw-bold text-decoration-none">#${v.reversal_voucher_id}</a>
                    </div>
                `;
            } else if (v.original_voucher_id) {
                reversalInfo = `
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-link me-2"></i>هذا سند عكسي للسند الأصلي رقم: 
                        <a href="#" onclick="viewVoucher(${v.original_voucher_id}); return false;" class="fw-bold text-decoration-none">#${v.original_voucher_id}</a>
                    </div>
                `;
            }

            let html = `
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold mb-0">${v.transaction_number}</h4>
                    <span class="badge ${statusClass} p-2 px-3 rounded-pill">${statusLabel}</span>
                </div>
                ${reversalInfo}
                <div class="row g-3 mb-4">
                    <div class="col-6"><small class="text-muted d-block">التاريخ</small><strong>${v.transaction_date}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">المبلغ</small><strong class="text-primary fs-5">${parseFloat(v.amount).toLocaleString()} ${v.currency_symbol}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">الحساب</small><strong>${v.account_name}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">الجهة</small><strong>${v.party_name}</strong></div>
                </div>
                <div class="mb-4"><small class="text-muted d-block">البيان</small><div class="p-3 bg-light rounded">${v.description || '---'}</div></div>
                <div class="text-center mt-4">
                    <button class="btn btn-secondary px-4 rounded-pill" data-bs-dismiss="modal">إغلاق</button>
                </div>
            `;
            $('#viewContent').html(html);
            $('#viewModal').modal('show');
        });
    };

    // جسر لدالة التعديل
    window.editVoucher = function (id) {
        if (typeof window.editVoucherLocal === 'function') {
            window.editVoucherLocal(id);
        } else {
            console.error('editVoucherLocal not found');
            location.href = '?edit_id=' + id;
        }
    };

    // دالة الترحيل
    window.postVoucher = function (id) {
        Swal.fire({
            title: 'هل تريد ترحيل السند؟',
            text: "بمجرد الترحيل، سيتم تحديث أرصدة الحسابات ولا يمكن تعديل السند مباشرة.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، ترحيل',
            cancelButtonText: 'تراجع'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'ajax/post_voucher.php',
                    type: 'POST',
                    data: {
                        id: id,
                        csrf_token: typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : ''
                    },
                    dataType: 'json',
                    success: function (res) {
                        if (res.success) {
                            Swal.fire('تم الترحيل!', 'تم ترحيل السند وتحديث الأرصدة بنجاح.', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ!', res.message || 'فشل ترحيل السند.', 'error');
                        }
                    },
                    error: function () {
                        Swal.fire('خطأ!', 'حدث خطأ في الاتصال بالخادم.', 'error');
                    }
                });
            }
        });
    };

    // جسر لدالة الإلغاء/العكس (للتوافق مع المسميات في الـ HTML)
    window.cancelVoucher = function (id) {
        window.reverseVoucher(id);
    };

    console.log('✅ receipts-actions.js with Reversal Logic loaded');
});
