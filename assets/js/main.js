/* ============================================
   USAT COLLEGE ARCHIVING SYSTEM
   PREMIUM JAVASCRIPT
   ============================================ */

// DOM Ready
$(document).ready(function() {
    // Initialize all components
    initAnimations();
    initDarkMode();
    initTooltips();
    initCharts();
    initSearchFilters();
    initFormValidation();
    initDataTables();
    
    // Auto-hide alerts
    setTimeout(function() {
        $('.alert').fadeOut('slow', function() {
            $(this).remove();
        });
    }, 5000);
});

// ============================================
// ANIMATIONS
// ============================================
function initAnimations() {
    // Scroll animations
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.stat-card, .table-container, .card').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'all 0.6s ease';
        observer.observe(el);
    });
    
    // Button ripple effect
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple');
            this.appendChild(ripple);
            
            const x = e.clientX - e.target.offsetLeft;
            const y = e.clientY - e.target.offsetTop;
            
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            
            setTimeout(() => ripple.remove(), 600);
        });
    });
}

// Add ripple CSS
const style = document.createElement('style');
style.textContent = `
    .btn {
        position: relative;
        overflow: hidden;
    }
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.5);
        transform: scale(0);
        animation: ripple 0.6s linear;
        pointer-events: none;
    }
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// ============================================
// DARK MODE
// ============================================
function initDarkMode() {
    // Add dark mode toggle to navbar
    const toggleButton = $(`
        <button class="dark-mode-toggle" id="darkModeToggle">
            <i class="fas fa-moon"></i>
            <span>Dark Mode</span>
        </button>
    `);
    
    $('.user-info').before(toggleButton);
    
    // Check saved preference
    const darkMode = localStorage.getItem('darkMode');
    if (darkMode === 'enabled') {
        $('body').addClass('dark-mode');
        $('#darkModeToggle i').removeClass('fa-moon').addClass('fa-sun');
        $('#darkModeToggle span').text('Light Mode');
    }
    
    // Toggle dark mode
    $('#darkModeToggle').on('click', function() {
        $('body').toggleClass('dark-mode');
        const isDark = $('body').hasClass('dark-mode');
        
        if (isDark) {
            $(this).find('i').removeClass('fa-moon').addClass('fa-sun');
            $(this).find('span').text('Light Mode');
            localStorage.setItem('darkMode', 'enabled');
            showToast('Dark mode enabled', 'success');
        } else {
            $(this).find('i').removeClass('fa-sun').addClass('fa-moon');
            $(this).find('span').text('Dark Mode');
            localStorage.setItem('darkMode', 'disabled');
            showToast('Light mode enabled', 'success');
        }
    });
}

// ============================================
// TOOLTIPS
// ============================================
function initTooltips() {
    $('[title]').each(function() {
        const title = $(this).attr('title');
        $(this).attr('data-title', title);
        $(this).removeAttr('title');
        
        $(this).hover(function(e) {
            const tooltip = $(`<div class="custom-tooltip">${title}</div>`);
            $('body').append(tooltip);
            tooltip.css({
                top: e.pageY - 30,
                left: e.pageX + 10,
                position: 'absolute',
                background: 'linear-gradient(135deg, #667eea, #764ba2)',
                color: 'white',
                padding: '5px 10px',
                borderRadius: '8px',
                fontSize: '12px',
                zIndex: 9999,
                whiteSpace: 'nowrap'
            });
            
            $(this).mouseleave(() => tooltip.remove());
        });
    });
}

// ============================================
// CHARTS
// ============================================
function initCharts() {
    if (typeof Chart !== 'undefined') {
        // Registration Chart
        if (document.getElementById('registrationChart')) {
            new Chart(document.getElementById('registrationChart'), {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'New Students',
                        data: [12, 19, 15, 25, 22, 30],
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#6366f1',
                        pointBorderColor: 'white',
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, boxWidth: 10 }
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#fff',
                            bodyColor: '#cbd5e1',
                            borderColor: '#6366f1',
                            borderWidth: 1
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.05)' },
                            ticks: { stepSize: 10 }
                        },
                        x: {
                            grid: { display: false }
                        }
                    }
                }
            });
        }
        
        // Course Chart
        if (document.getElementById('courseChart')) {
            new Chart(document.getElementById('courseChart'), {
                type: 'doughnut',
                data: {
                    labels: ['BSIT', 'BSCS', 'BSIS', 'BSBA'],
                    datasets: [{
                        data: [45, 30, 20, 25],
                        backgroundColor: ['#6366f1', '#8b5cf6', '#ec4899', '#f59e0b'],
                        borderWidth: 0,
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, boxWidth: 10 }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '65%'
                }
            });
        }
    }
}

// ============================================
// SEARCH FILTERS
// ============================================
function initSearchFilters() {
    $('#searchInput').on('keyup', function() {
        const value = $(this).val().toLowerCase();
        $('.data-table tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
}

// ============================================
// FORM VALIDATION
// ============================================
function initFormValidation() {
    $('form').on('submit', function(e) {
        let isValid = true;
        
        $(this).find('[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            showToast('Please fill in all required fields', 'error');
        } else {
            const submitBtn = $(this).find('button[type="submit"]');
            submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Processing...');
            submitBtn.prop('disabled', true);
        }
    });
    
    // Add invalid style
    const style = document.createElement('style');
    style.textContent = `
        .is-invalid {
            border-color: #ef4444 !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='red' viewBox='0 0 16 16'%3E%3Cpath d='M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM8 4a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 4zm.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
    `;
    document.head.appendChild(style);
}

// ============================================
// DATA TABLES
// ============================================
function initDataTables() {
    // Add search to tables
    $('.data-table').each(function() {
        const tableId = $(this).attr('id') || 'table-' + Math.random();
        $(this).attr('id', tableId);
        
        const searchHtml = `
            <div class="table-search mb-3">
                <input type="text" id="search-${tableId}" class="form-control" placeholder="Search table...">
            </div>
        `;
        $(this).before(searchHtml);
        
        $(`#search-${tableId}`).on('keyup', function() {
            const value = $(this).val().toLowerCase();
            $(`#${tableId} tbody tr`).filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
            });
        });
    });
}

// ============================================
// TOAST NOTIFICATIONS
// ============================================
function showToast(message, type = 'success') {
    const toastContainer = $('#toastContainer');
    if (toastContainer.length === 0) {
        $('body').append('<div class="toast-container" id="toastContainer"></div>');
    }
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-circle' : 
                 type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
    
    const toast = $(`
        <div class="toast toast-${type}">
            <i class="fas ${icon}"></i>
            <div class="toast-content">
                <strong>${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <p>${message}</p>
            </div>
            <button class="toast-close">&times;</button>
        </div>
    `);
    
    $('#toastContainer').append(toast);
    
    toast.find('.toast-close').on('click', function() {
        toast.remove();
    });
    
    setTimeout(() => {
        toast.fadeOut('slow', function() {
            $(this).remove();
        });
    }, 5000);
}

// Add toast styles
const toastStyles = document.createElement('style');
toastStyles.textContent = `
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    }
    .toast {
        background: white;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 12px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 300px;
        animation: slideInRight 0.3s ease;
        border-left: 4px solid;
    }
    .toast-success { border-left-color: #10b981; }
    .toast-error { border-left-color: #ef4444; }
    .toast-warning { border-left-color: #f59e0b; }
    .toast-info { border-left-color: #3b82f6; }
    .toast i:first-child { font-size: 20px; }
    .toast-success i:first-child { color: #10b981; }
    .toast-error i:first-child { color: #ef4444; }
    .toast-content { flex: 1; }
    .toast-content strong { display: block; font-size: 14px; margin-bottom: 4px; }
    .toast-content p { font-size: 12px; color: #666; margin: 0; }
    .toast-close {
        background: none;
        border: none;
        font-size: 20px;
        cursor: pointer;
        color: #999;
        transition: 0.3s;
    }
    .toast-close:hover { color: #333; }
    @keyframes slideInRight {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }
`;
document.head.appendChild(toastStyles);

// ============================================
// CONFIRM DIALOG
// ============================================
function confirmAction(message, callback) {
    const result = confirm(message);
    if (result && callback) callback();
    return result;
}

// ============================================
// EXPORT FUNCTIONS
// ============================================
function exportToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let row of rows) {
        const rowData = [];
        const cols = row.querySelectorAll('td, th');
        for (let col of cols) {
            rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
        }
        csv.push(rowData.join(','));
    }
    
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
    
    showToast('Export completed successfully', 'success');
}

function printReport() {
    window.print();
    showToast('Print dialog opened', 'info');
}

// ============================================
// QR CODE SCANNER
// ============================================
let html5QrCode = null;

function initQRScanner(elementId = 'qr-reader') {
    if (typeof Html5Qrcode === 'undefined') {
        console.warn('Html5Qrcode library not loaded');
        return;
    }
    
    html5QrCode = new Html5Qrcode(elementId);
    const config = { fps: 10, qrbox: { width: 250, height: 250 } };
    
    html5QrCode.start(
        { facingMode: "environment" },
        config,
        (decodedText) => {
            onQRCodeScanned(decodedText);
            stopQRScanner();
        },
        (error) => {}
    ).catch(err => {
        console.error("Unable to start scanning", err);
        showToast('Camera access denied or not supported', 'error');
    });
}

function stopQRScanner() {
    if (html5QrCode) {
        html5QrCode.stop().catch(err => console.log(err));
        html5QrCode = null;
    }
}

function onQRCodeScanned(data) {
    const match = data.match(/Student ID: (\S+)/);
    if (match) {
        searchStudentByID(match[1]);
    } else {
        showToast('Invalid QR code format', 'error');
    }
}

function searchStudentByID(studentId) {
    $.ajax({
        url: 'api/get_students.php',
        method: 'POST',
        data: { student_id: studentId },
        success: function(response) {
            const student = JSON.parse(response);
            if (student && student.id) {
                displayStudentResult(student);
                showToast(`Student found: ${student.first_name} ${student.last_name}`, 'success');
            } else {
                showToast('Student not found', 'error');
            }
        },
        error: function() {
            showToast('Error searching for student', 'error');
        }
    });
}

function displayStudentResult(student) {
    const resultDiv = document.getElementById('student-result');
    if (!resultDiv) return;
    
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = `
        <div class="card" style="animation: fadeInUp 0.5s ease;">
            <div class="card-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                <h5><i class="fas fa-user-check"></i> Student Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-id-card"></i> Student ID:</strong> ${student.student_id}</p>
                        <p><strong><i class="fas fa-user"></i> Name:</strong> ${student.first_name} ${student.last_name}</p>
                        <p><strong><i class="fas fa-graduation-cap"></i> Course:</strong> ${student.course}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><i class="fas fa-calendar-alt"></i> Year Level:</strong> ${student.year_level}</p>
                        <p><strong><i class="fas fa-envelope"></i> Email:</strong> ${student.email || 'N/A'}</p>
                        <p><strong><i class="fas fa-phone"></i> Phone:</strong> ${student.phone || 'N/A'}</p>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <a href="edit_student.php?id=${student.id}" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Student
                    </a>
                    <a href="documents.php?student_id=${student.id}" class="btn btn-info">
                        <i class="fas fa-file-alt"></i> View Documents
                    </a>
                </div>
            </div>
        </div>
    `;
}

// Export functions to global scope
window.showToast = showToast;
window.confirmAction = confirmAction;
window.exportToCSV = exportToCSV;
window.printReport = printReport;
window.initQRScanner = initQRScanner;
window.stopQRScanner = stopQRScanner;