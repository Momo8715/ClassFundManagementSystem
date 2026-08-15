/**
 * 班级班费管理系统 - 客户端脚本
 * 兼容 QQ/微信 WebView（无 ?. / const / let）
 */
(function () {
    'use strict';

    // 兼容 Cloudflare Rocket Loader：其会将内联 onclick 改写为
    // "if (!window.__cfRLUnblockHandlers) return false; xxx()"。
    // 当 Rocket Loader 初始化失败时该标记缺失，导致所有按钮点击失效。
    // 此处主动声明已解锁，保证内联事件处理器始终执行。
    window.__cfRLUnblockHandlers = true;

    // ========== 常量 ==========
    var API = 'api.php';
    var ROLE_LABELS = { head_teacher: '班主任', admin: '管理员', monitor: '班长', vice_monitor: '副班长', finance: '财务委员', student: '同学' };
    var ROLE_PRIORITY = { head_teacher: 1, admin: 2, monitor: 3, vice_monitor: 4, finance: 5, student: 6 };
    var permsEl = document.getElementById('perms-data');
    var PERMS = permsEl ? JSON.parse(permsEl.textContent || '{}') : {};
    var INCOME_SUBS = { '班费收缴': '班费收缴', '其他来源': '其他来源' };
    var EXPENSE_SUBS = { '日常支出': '日常支出', '活动支出': '活动支出', '设备采购': '设备采购', '其他支出': '其他支出' };

    // ========== 全局状态 ==========
    var currentUser = null;
    var _t = '';
    var chartTrend = null, chartPie = null, reportBarChart = null, reportPieChart = null;
    var importPreviewData = [];
    var xlsxPreviewData = [];
    var txPage = 1;
    var txImageList = [];

    // ========== 工具函数 ==========

    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // 凭证图 URL：数字 = 数据库图片 ID（v1.5.6，thumb=1 输出缩略图）；字符串 = 旧版文件路径（兼容）
    function imgUrl(item, thumb) {
        if (typeof item === 'number') return API + '?action=tx_image&id=' + item + (thumb ? '&thumb=1' : '');
        return String(item);
    }

    function toast(msg, type) {
        type = type || 'success';
        var t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 3000);
    }

    function showLoading(containerId) {
        var el = document.getElementById(containerId);
        if (el) el.innerHTML = '<div class="loading"><div class="spinner"></div><p>加载中...</p></div>';
    }

    function getFingerprint() {
        var d = [navigator.userAgent, navigator.language, screen.width + 'x' + screen.height,
            new Date().getTimezoneOffset(), navigator.hardwareConcurrency || 0,
            navigator.deviceMemory || 0, navigator.platform || ''
        ];
        var s = d.join('|'), r = '';
        for (var i = 0; i < Math.min(s.length, 200); i++) {
            r += s.charCodeAt(i).toString(16);
        }
        return r.substring(0, 64);
    }

    // ========== API 调用 ==========

    async function api(action, params, method) {
        method = method || 'GET';
        var url = API + '?action=' + action;
        var opts = { method: method, credentials: 'same-origin' };
        if (_t && method !== 'GET') {
            if (!params) params = {};
            params['csrf_' + 'token'] = _t;
        }
        if (method === 'GET') {
            var qs = new URLSearchParams(params).toString();
            if (qs) url += '&' + qs;
        } else {
            opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
            var entries = [];
            for (var k in params) {
                if (!params.hasOwnProperty(k)) continue;
                entries.push([k, typeof params[k] === 'object' ? JSON.stringify(params[k]) : params[k]]);
            }
            opts.body = new URLSearchParams(entries);
        }
        var r = await fetch(url, opts);
        var data = await r.json();
        if (!r.ok) throw new Error(data.error || '请求失败');
        return data;
    }

    // ========== 角色/权限 ==========
    function sortRoles(r) { return (r || []).slice().sort(function (a, b) { return (ROLE_PRIORITY[a] || 99) - (ROLE_PRIORITY[b] || 99); }); }
    function getHighestRole(r) { var b = 'student', bp = 99; for (var i = 0; i < (r || []).length; i++) { var p = ROLE_PRIORITY[r[i]] || 99; if (p < bp) { bp = p; b = r[i]; } } return b; }
    function hasPerm(a) { if (!currentUser) return false; var allowed = PERMS[a] || []; return allowed.some(function (r) { return currentUser.roles.includes(r); }); }

    // ========== 认证 ==========

    async function doLogin() {
        var u = document.getElementById('loginUser').value.trim();
        var p = document.getElementById('loginPass').value;
        var debugEl = document.getElementById('loginDebug');
        debugEl.style.display = 'block';
        debugEl.textContent = '连接中...';
        if (!u || !p) return toast('请填写用户名和密码', 'error');
        try {
            var d = await api('login', { username: u, pwd: p, fingerprint: getFingerprint() }, 'POST');
            currentUser = d.user;
            if (currentUser.roles) currentUser.roles = sortRoles(currentUser.roles);
            _t = d.user['csrf_' + 'token'] || '';
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('app').classList.add('active');
            updateSidebar();
            refreshUI();
            switchPage('dashboard');
            document.getElementById('loginError').style.display = 'none';
            toast('欢迎，' + escapeHtml(currentUser.username) + '！');
        } catch (e) {
            document.getElementById('loginError').style.display = 'block';
            document.getElementById('loginError').textContent = e.message;
            debugEl.style.display = 'none';
        }
    }

    async function doGuestLogin() {
        try {
            var d = await api('guest_login', { fingerprint: getFingerprint() }, 'POST');
            currentUser = d.user;
            _t = d.user['csrf_' + 'token'] || '';
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('app').classList.add('active');
            updateSidebar();
            refreshUI();
            switchPage('dashboard');
            toast('👋 欢迎！');
        } catch (e) { toast(e.message, 'error'); }
    }

    async function doLogout() {
        try { await api('logout', {}, 'POST'); } catch (e) {}
        currentUser = null;
        _t = '';
        document.getElementById('loginScreen').style.display = 'flex';
        document.getElementById('app').classList.remove('active');
    }

    // ========== 侧边栏 ==========

    function updateSidebar() {
        if (!currentUser) return;
        var s = sortRoles(currentUser.roles || []);
        var h = s.length ? getHighestRole(s) : 'student';
        var g = currentUser.is_guest || currentUser.id === 0;
        var html = escapeHtml(currentUser.username);
        if (g) html += ' <span style="font-size:10px;background:#fef3c7;color:#d97706;padding:2px 6px;border-radius:4px">访客</span>';
        html += '<br><span style="font-size:10px;background:var(--primary-light);color:var(--primary);padding:1px 6px;border-radius:4px">' + escapeHtml(ROLE_LABELS[h] || h) + '</span>';
        document.getElementById('sidebarUser').innerHTML = html;
    }

    function refreshUI() {
        var items = [
            ['navImport', 'importData'],
            ['navRoster', 'manageRoster', 'deleteRoster'],
            ['navPayments', 'viewPayments'],
            ['navLogs', 'viewLogs'],
            ['navSecurity', 'viewSecurity'],
            ['navRecycle', 'deleteTransaction']
        ];
        items.forEach(function (item) {
            var el = document.getElementById(item[0]);
            if (!el) return;
            var ok = false;
            for (var i = 1; i < item.length; i++) { if (hasPerm(item[i])) { ok = true; break; } }
            el.style.display = ok ? '' : 'none';
        });
        document.getElementById('navStudents').style.display = (hasPerm('manageStudents') || hasPerm('manageAllAccounts')) ? '' : 'none';
        document.getElementById('btnAddTx').style.display = hasPerm('addTransaction') ? '' : 'none';
        var bd = document.getElementById('btnBatchDel');
        if (bd) bd.style.display = hasPerm('deleteTransaction') ? '' : 'none';
        updateSidebar();
    }

    function switchPage(p) {
        document.querySelectorAll('.page').forEach(function (x) { x.classList.remove('active'); });
        document.querySelectorAll('.sidebar-nav button').forEach(function (x) { x.classList.remove('active'); });
        var pg = document.getElementById('page-' + p);
        if (pg) pg.classList.add('active');
        var nv = document.querySelector('[data-page="' + p + '"]');
        if (nv) nv.classList.add('active');
        var handlers = { dashboard: renderDashboard, transactions: renderTransactions, report: renderReport, students: renderStudents, roster: renderRoster, payments: renderPayments, security: renderSecurity, recycle: renderRecycle, logs: renderLogs };
        if (handlers[p]) handlers[p]();
        // 移动端切换页面后自动关闭抽屉
        toggleSidebar(false);
    }

    // 移动端抽屉式侧边栏
    function toggleSidebar(open) {
        var sb = document.getElementById('mainSidebar');
        var ov = document.getElementById('sidebarOverlay');
        if (!sb) return;
        var isOpen = typeof open === 'boolean' ? open : !sb.classList.contains('open');
        if (isOpen) { sb.classList.add('open'); if (ov) ov.classList.add('show'); }
        else { sb.classList.remove('open'); if (ov) ov.classList.remove('show'); }
    }

    // ========== 弹窗 ==========
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function _f1() {
        if (currentUser && currentUser.id === 0) return toast('访客不能修改密码', 'error');
        document.getElementById('oldPassword').value = '';
        document.getElementById('newPassword').value = '';
        openModal('modalPwd');
    }

    async function _f2() {
        var oldPwdVal = document.getElementById('oldPassword').value;
        var newPwdVal = document.getElementById('newPassword').value;
        if (!oldPwdVal || !newPwdVal) return toast('请填写完整', 'error');
        if (newPwdVal.length < 4) return toast('新密码至少4位', 'error');
        try {
            await api('change_' + 'password', { old_pwd: oldPwdVal, new_pwd: newPwdVal }, 'POST');
            closeModal('modalPwd');
            toast('密码已修改');
        } catch (e) { toast(e.message, 'error'); }
    }

    // ========== 主题 ==========
    function toggleTheme() {
        var t = document.documentElement;
        var isDark = t.classList.contains("dark");
        if (isDark) { t.classList.remove("dark"); localStorage.setItem("theme", "light"); }
        else { t.classList.add("dark"); localStorage.setItem("theme", "dark"); }
        var btn = document.getElementById("themeBtn");
        if (btn) btn.textContent = isDark ? "🌙" : "☀️";
    }

    // ========== 仪表盘 ==========

    async function renderDashboard() {
        try {
            var d = await api('dashboard');
            var s = d.summary;
            var cardsHtml = '<div class="card"><div class="card-label">💰 总收入</div><div class="card-value income">¥' + s.totalIncome.toFixed(2) + '</div><div class="card-sub">' + s.incomeCount + ' 笔</div></div>' +
                '<div class="card"><div class="card-label">💸 总支出</div><div class="card-value expense">¥' + s.totalExpense.toFixed(2) + '</div><div class="card-sub">' + s.expenseCount + ' 笔</div></div>' +
                '<div class="card"><div class="card-label">🏦 结余</div><div class="card-value balance">¥' + s.balance.toFixed(2) + '</div><div class="card-sub">' + (s.balance >= 0 ? '✅ 正常' : '⚠️ 超支') + '</div></div>' +
                (hasPerm('viewSecurity') ? '<div class="card"><div class="card-label">👥 人数</div><div class="card-value" style="color:#6366f1">' + s.userCount + '</div><div class="card-sub">用户总数</div></div>' : '');
            document.getElementById('dashboardCards').innerHTML = cardsHtml;
            var recent = d.recent || [];
            var recHtml = '';
            if (recent.length) {
                recHtml = '<table><thead><tr><th>日期</th><th>类型</th><th>金额</th><th>描述</th><th>分类</th><th>记录人</th></tr></thead><tbody>';
                recent.forEach(function (t) {
                    recHtml += '<tr><td>' + escapeHtml(t.date) + '</td><td><span class="badge badge-' + (t.type === 'income' ? 'income' : 'expense') + '">' + (t.type === 'income' ? '收入' : '支出') + '</span></td>' +
                        '<td style="font-weight:700;color:' + (t.type === 'income' ? 'var(--success)' : 'var(--danger)') + '">' + (t.type === 'income' ? '+' : '-') + '¥' + Number(t.amount).toFixed(2) + '</td>' +
                        '<td>' + escapeHtml(t.description) + '</td><td>' + escapeHtml(t.category) + '</td><td>' + escapeHtml(t.recorder_name || '未知') + '</td></tr>';
                });
                recHtml += '</tbody></table>';
            } else { recHtml = '<div class="empty"><div class="icon">📭</div>暂无记录</div>'; }
            document.getElementById('dashboardRecent').innerHTML = recHtml;
            renderCharts();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function renderCharts() {
        try {
            var d = await api('transactions', { per_page: 1000 });
            var list = d.transactions || [];
            var months = {}, cats = {};
            list.forEach(function (t) {
                var m = t.date.substring(0, 7);
                if (!months[m]) months[m] = { income: 0, expense: 0 };
                if (t.type === 'income') months[m].income += parseFloat(t.amount);
                else { months[m].expense += parseFloat(t.amount); cats[t.category] = (cats[t.category] || 0) + parseFloat(t.amount); }
            });
            var keys = Object.keys(months).sort();
            var trendEl = document.getElementById('chartTrend');
            var ctx1 = trendEl ? trendEl.getContext('2d') : null;
            if (ctx1 && window.Chart) {
                if (chartTrend) chartTrend.destroy();
                chartTrend = new Chart(ctx1, { type: 'line', data: { labels: keys, datasets: [
                    { label: '收入', data: keys.map(function (k) { return months[k].income; }), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.1)', fill: true, tension: .3 },
                    { label: '支出', data: keys.map(function (k) { return months[k].expense; }), borderColor: '#f43f5e', backgroundColor: 'rgba(244,63,94,.1)', fill: true, tension: .3 }
                ]}, options: { responsive: true, plugins: { legend: { labels: { font: { size: 11 } } } }, scales: { y: { ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } } } });
            }
            var catKeys = Object.keys(cats).sort(function (a, b) { return cats[b] - cats[a]; }).slice(0, 6);
            var pieEl = document.getElementById('chartPie');
            var ctx2 = pieEl ? pieEl.getContext('2d') : null;
            if (ctx2 && catKeys.length && window.Chart) {
                if (chartPie) chartPie.destroy();
                chartPie = new Chart(ctx2, { type: 'doughnut', data: { labels: catKeys, datasets: [{ data: catKeys.map(function (k) { return cats[k]; }), backgroundColor: ['#6366f1','#f43f5e','#f59e0b','#10b981','#8b5cf6','#ec4899'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 10 } } } } });
            }
        } catch (e) {}
    }

    // ========== 收支记录 ==========

    async function renderTransactions(page) {
        page = page || txPage;
        try {
            showLoading('transactionTable');
            var p = { page: page, per_page: 20 };
            var t = document.getElementById('filterType').value;
            var m = document.getElementById('filterMonth').value;
            var s = document.getElementById('filterSearch').value.trim();
            if (t) p.type = t;
            if (m) p.month = m;
            if (s) p.search = s;
            var d = await api('transactions', p);
            var list = d.transactions || [];
            txPage = page;
            var ce = hasPerm('editTransaction'), cd = hasPerm('deleteTransaction');
            var html = '';
            if (list.length === 0) {
                html = '<div class="empty"><div class="icon">📭</div>暂无记录</div>';
            } else {
                html = '<table><thead><tr>' + (cd ? '<th style="width:28px"><input type="checkbox" onclick="window._toggleAllTx(this)"></th>' : '') + '<th>ID</th><th>日期</th><th>类型</th><th>金额</th><th>描述</th><th>分类</th><th>凭证</th><th>记录人</th>' + (ce || cd ? '<th>操作</th>' : '') + '</tr></thead><tbody>';
                list.forEach(function (t) {
                    var imgs = [];
                    // v1.5.6：优先数据库凭证图（缩略图加载）
                    if (t.image_ids) { try { var iids = typeof t.image_ids === 'string' ? JSON.parse(t.image_ids) : t.image_ids; if (Array.isArray(iids)) imgs = iids.filter(Boolean); } catch (e) {} }
                    if (!imgs.length) {
                        if (t.images) { try { var ii = typeof t.images === 'string' ? JSON.parse(t.images) : t.images; if (Array.isArray(ii)) imgs = ii.filter(Boolean); } catch (e) {} }
                        if (!imgs.length && t.image_path) imgs = [t.image_path];
                    }
                    var imgCell = imgs.length ? '<td>' + imgs.map(function (p) { return '<a href="' + escapeHtml(imgUrl(p, false)) + '" target="_blank" title="查看原图"><img src="' + escapeHtml(imgUrl(p, true)) + '" style="width:30px;height:24px;object-fit:cover;border-radius:4px;margin-right:2px;border:1px solid var(--border)" loading="lazy"></a>'; }).join('') + '</td>' : '<td style="color:var(--text-secondary)">-</td>';
                    html += '<tr>' + (cd ? '<td><input type="checkbox" class="txCb" value="' + t.id + '"></td>' : '') + '<td>#' + t.id + '</td><td>' + escapeHtml(t.date) + '</td><td><span class="badge badge-' + (t.type === 'income' ? 'income' : 'expense') + '">' + (t.type === 'income' ? '收入' : '支出') + '</span></td>' +
                        '<td style="font-weight:700;color:' + (t.type === 'income' ? 'var(--success)' : 'var(--danger)') + '">' + (t.type === 'income' ? '+' : '-') + '¥' + Number(t.amount).toFixed(2) + '</td>' +
                        '<td>' + escapeHtml(t.description) + '</td><td>' + escapeHtml(t.category) + '</td>' + imgCell + '<td>' + escapeHtml(t.recorder_name || '未知') + '</td>';
                    if (ce || cd) { html += '<td style="white-space:nowrap">' + '<button class="btn btn-outline btn-sm" onclick="window._receipt(' + t.id + ')" title="收据">🧾</button> ' + (ce ? '<button class="btn btn-outline btn-sm" onclick="window._editTx(' + t.id + ')">✏️</button>' : '') + (cd ? '<button class="btn btn-danger btn-sm" onclick="window._deleteTx(' + t.id + ')" style="margin-left:4px">🗑️</button>' : '') + '</td>'; }
                    html += '</tr>';
                });
                html += '</tbody></table>';
            }
            document.getElementById('transactionTable').innerHTML = html;
            var pagHtml = '';
            if (d.total_pages > 1) { pagHtml = '<div class="pagination">'; for (var i = 1; i <= d.total_pages; i++) pagHtml += '<button class="' + (i === page ? 'active' : '') + '" onclick="window._renderTxPage(' + i + ')">' + i + '</button>'; pagHtml += '</div>'; }
            document.getElementById('transactionPagination').innerHTML = pagHtml;
        } catch (e) { toast(e.message, 'error'); }
    }

    // ========== 学期汇总报表 ==========
    async function renderReport() {
        try {
            var ySel = document.getElementById('reportYear');
            var sSel = document.getElementById('reportSemester');
            // 首次进入：尝试加载用户自定义学期作为选项（保证与学期管理数据一致）
            if (!window._semLoaded) {
                window._semLoaded = true;
                try {
                    var sd = await api('semesters');
                    var sems = sd.semesters || [];
                    if (sems.length) {
                        // 存在自定义学期：清空固定「春季/秋季」选项，仅保留自定义学期，避免两个体系混选
                        while (sSel.firstChild) sSel.removeChild(sSel.firstChild);
                        sems.forEach(function (s) {
                            var o = document.createElement('option');
                            o.value = 'id:' + s.id;
                            o.textContent = s.name + '（' + s.start_date + ' ~ ' + s.end_date + '）' + (s.status === 'active' ? ' · 进行中' : ' · 已归档');
                            sSel.appendChild(o);
                        });
                        // 默认选中进行中的学期；无进行中则选最新的
                        var active = sems.filter(function (x) { return x.status === 'active'; });
                        sSel.value = 'id:' + (active.length ? active[0].id : sems[0].id);
                        // 自定义学期已含起止日期，隐藏年份下拉框（避免无效交互）
                        if (ySel) ySel.style.display = 'none';
                    } else if (ySel) {
                        // 无自定义学期：显示年份下拉框，走内置春季/秋季逻辑
                        ySel.style.display = '';
                    }
                } catch (e) {
                    if (ySel) ySel.style.display = '';
                }
            }
            var selVal = sSel.value;
            var p = {};
            if (selVal.indexOf('id:') === 0) {
                p.semester_id = selVal.substring(3);
            } else {
                if (ySel.options.length === 0) {
                    var cy = new Date().getFullYear();
                    for (var i = 0; i < 6; i++) {
                        var o = document.createElement('option');
                        o.value = String(cy - i);
                        o.textContent = (cy - i) + ' 年';
                        ySel.appendChild(o);
                    }
                    ySel.value = String(cy);
                }
                p.year = ySel.value;
                p.semester = selVal;
            }
            showLoading('reportContent');
            var d;
            // 性能优化：相同学期参数 30 秒内直接使用缓存，避免重复请求
            var cacheKey = JSON.stringify(p);
            if (window._reportCache && window._reportCache.key === cacheKey && Date.now() - window._reportCache.ts < 30000) {
                d = window._reportCache.data;
            } else {
                d = await api('report_semester', p);
                window._reportCache = { key: cacheKey, data: d, ts: Date.now() };
            }
            var s = d.summary, pd = d.period;

            // 汇总卡片（期末结余大字强调）
            var html = '<div class="cards">' +
                '<div class="card" style="background:linear-gradient(135deg,var(--primary),#6d8bff);color:#fff"><div class="card-label" style="color:rgba(255,255,255,.85)">🏦 期末结余</div><div class="card-value" style="font-size:28px;color:#fff">¥' + s.balance.toFixed(2) + '</div><div class="card-sub" style="color:rgba(255,255,255,.8)">' + (s.balance >= 0 ? '✅ 收支正常' : '⚠️ 已超支') + '</div></div>' +
                '<div class="card"><div class="card-label">📆 期初余额</div><div class="card-value" style="color:var(--text)">¥' + s.begin_balance.toFixed(2) + '</div><div class="card-sub">学期开始前累计</div></div>' +
                '<div class="card"><div class="card-label">💰 总收入</div><div class="card-value income">¥' + s.total_income.toFixed(2) + '</div><div class="card-sub">' + s.income_count + ' 笔</div></div>' +
                '<div class="card"><div class="card-label">💸 总支出</div><div class="card-value expense">¥' + s.total_expense.toFixed(2) + '</div><div class="card-sub">' + s.expense_count + ' 笔</div></div>' +
                '<div class="card"><div class="card-label">🎯 班费收缴率</div><div class="card-value" style="color:' + (s.collect_rate >= 90 ? 'var(--success)' : '#f59e0b') + '">' + s.collect_rate + '%</div><div class="card-sub">¥' + s.collected_amount.toFixed(2) + ' / 应收 ¥' + s.expected_amount.toFixed(2) + '（' + s.rounds + ' 轮）</div></div>' +
                '</div>';

            // 分类汇总
            var inc = [], exp = [];
            (d.by_category || []).forEach(function (c) { (c.type === 'income' ? inc : exp).push(c); });
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px">';
            html += '<div><h4 style="margin-bottom:8px;color:var(--success)">📥 收入分类</h4><div class="table-wrap">' + catTable(inc) + '</div></div>';
            html += '<div><h4 style="margin-bottom:8px;color:var(--danger)">📤 支出分类</h4><div class="table-wrap">' + catTable(exp) + '</div></div>';
            html += '</div>';

            // 月度趋势
            html += '<h4 style="margin-top:16px;color:var(--text-secondary)">📅 月度收支明细</h4><div class="table-wrap">';
            var mh = (d.by_month || []).length === 0 ? '<div class="empty">该学期暂无收支记录</div>' :
                '<table><thead><tr><th>月份</th><th>收入</th><th>支出</th><th>净额</th></tr></thead><tbody>' +
                d.by_month.map(function (m) {
                    var net = Number(m.net);
                    return '<tr><td>' + escapeHtml(m.month) + '</td><td style="color:var(--success)">+¥' + Number(m.income).toFixed(2) + '</td><td style="color:var(--danger)">-¥' + Number(m.expense).toFixed(2) + '</td><td style="font-weight:700;color:' + (net >= 0 ? 'var(--success)' : 'var(--danger)') + '">' + (net >= 0 ? '+' : '') + '¥' + net.toFixed(2) + '</td></tr>';
                }).join('') + '</tbody></table>';
            html += mh + '</div>';

    // 报表头信息
            html = '<div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px">📌 ' + escapeHtml(pd.label) + '（' + escapeHtml(pd.start) + ' ~ ' + escapeHtml(pd.end) + '）</div>' + html;

            document.getElementById('reportContent').innerHTML = html;

            // 图表：月度收支柱状图 + 支出分类占比饼图
            drawReportCharts(d);
            renderSemesters();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function renderSemesters() {
        try {
            var d = await api('semesters');
            var list = d.semesters || [];
            var box = document.getElementById('semesterTable');
            if (!box) return;
            if (!list.length) { box.innerHTML = '<div class="empty" style="padding:14px">暂无学期记录，可新建一个学期开始管理</div>'; return; }
            box.innerHTML = '<table><thead><tr><th>学期</th><th>起止日期</th><th>期初余额</th><th>期末结余</th><th>状态</th>' + (hasPerm('manageAllAccounts') ? '<th>操作</th>' : '') + '</tr></thead><tbody>' +
                list.map(function (s) {
                    return '<tr><td><b>' + escapeHtml(s.name) + '</b></td><td>' + escapeHtml(s.start_date) + ' ~ ' + escapeHtml(s.end_date) + '</td><td>¥' + Number(s.begin_balance).toFixed(2) + '</td><td style="font-weight:600;color:' + (Number(s.end_balance) >= 0 ? 'var(--success)' : 'var(--danger)') + '">¥' + Number(s.end_balance).toFixed(2) + '</td><td>' + (s.status === 'active' ? '<span class="badge badge-income">进行中</span>' : '<span class="badge badge-role">已归档</span>') + '</td>' + (hasPerm('manageAllAccounts') && s.status === 'active' ? '<td><button class="btn btn-outline btn-sm" onclick="window._archiveSemester(' + s.id + ')">📦 结转归档</button></td>' : '<td>-</td>') + '</tr>';
                }).join('') + '</tbody></table>';
        } catch (e) {}
    }

    async function _createSemester() {
        var name = document.getElementById('semName').value.trim();
        var start = document.getElementById('semStart').value;
        var end = document.getElementById('semEnd').value;
        if (!name) return toast('请填写学期名称', 'error');
        if (!start || !end) return toast('请选择起止日期', 'error');
        try {
            await api('semesters', { action: 'create', name: name, start_date: start, end_date: end }, 'POST');
            toast('学期创建成功');
            document.getElementById('semName').value = ''; document.getElementById('semStart').value = ''; document.getElementById('semEnd').value = '';
            renderSemesters();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function _archiveSemester(id) {
        if (!confirm('确定归档该学期？（归档后余额作为下一学期期初，历史数据可随时查看）')) return;
        try {
            await api('semesters', { action: 'archive', id: id }, 'POST');
            toast('已结转归档');
            renderSemesters();
        } catch (e) { toast(e.message, 'error'); }
    }

    function downloadReport() {
        var sSel = document.getElementById('reportSemester');
        var selVal = sSel.value;
        var qs;
        if (selVal.indexOf('id:') === 0) {
            qs = 'semester_id=' + selVal.substring(3);
        } else {
            var ySel = document.getElementById('reportYear');
            qs = 'year=' + encodeURIComponent(ySel.value || '') + '&semester=' + encodeURIComponent(selVal);
        }
        window.location.href = 'api.php?action=export_report&' + qs;
    }

    function drawReportCharts(d) {
        if (!window.Chart) return;
        var months = d.by_month || [];
        var barEl = document.getElementById('reportChartBar');
        if (barEl && months.length) {
            var ctxB = barEl.getContext('2d');
            if (reportBarChart) reportBarChart.destroy();
            reportBarChart = new Chart(ctxB, { type: 'bar', data: { labels: months.map(function (m) { return m.month; }), datasets: [
                { label: '收入', data: months.map(function (m) { return m.income; }), backgroundColor: 'rgba(16,185,129,.75)', borderRadius: 4 },
                { label: '支出', data: months.map(function (m) { return m.expense; }), backgroundColor: 'rgba(244,63,94,.75)', borderRadius: 4 }
            ]}, options: { responsive: true, plugins: { legend: { labels: { font: { size: 11 } } } }, scales: { y: { ticks: { font: { size: 10 } } }, x: { ticks: { font: { size: 10 } } } } } });
        }
        var exp = (d.by_category || []).filter(function (c) { return c.type === 'expense'; }).sort(function (a, b) { return b.total - a.total; }).slice(0, 6);
        var pieEl = document.getElementById('reportChartPie');
        if (pieEl && exp.length) {
            var ctxP = pieEl.getContext('2d');
            if (reportPieChart) reportPieChart.destroy();
            reportPieChart = new Chart(ctxP, { type: 'doughnut', data: { labels: exp.map(function (c) { return c.category; }), datasets: [{ data: exp.map(function (c) { return c.total; }), backgroundColor: ['#6366f1','#f43f5e','#f59e0b','#10b981','#8b5cf6','#ec4899'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, padding: 10 } } } } });
        }
    }

    function catTable(list) {
        if (!list || list.length === 0) return '<div class="empty" style="padding:16px">暂无数据</div>';
        var max = list[0].total || 1;
        return '<table><thead><tr><th>分类</th><th>笔数</th><th>金额</th><th style="width:30%">占比</th></tr></thead><tbody>' +
            list.map(function (c) {
                var pct = Math.round(Number(c.total) / max * 100);
                return '<tr><td>' + escapeHtml(c.category) + '</td><td>' + c.cnt + '</td><td style="font-weight:600">¥' + Number(c.total).toFixed(2) + '</td><td><div style="background:var(--border);border-radius:4px;height:8px;overflow:hidden"><div style="width:' + pct + '%;height:100%;background:' + (c.type === 'income' ? 'var(--success)' : 'var(--danger)') + '"></div></div></td></tr>';
            }).join('') + '</tbody></table>';
    }

    function showAddTransaction() {
        document.getElementById('modalTxTitle').textContent = '添加收支';
        document.getElementById('txId').value = '';
        document.getElementById('txType').value = 'income';
        onTxTypeChange();
        document.getElementById('txAmount').value = '';
        document.getElementById('txDate').value = (function () { var n = new Date(); return n.getFullYear() + '-' + (n.getMonth() < 9 ? '0' : '') + (n.getMonth() + 1) + '-' + (n.getDate() < 10 ? '0' : '') + n.getDate(); })();
        document.getElementById('txDesc').value = '';
        document.getElementById('txCategory').value = '班费';
        document.getElementById('txSourceInfo').value = '';
        document.getElementById('txImageFile').value = '';
        txImageList = [];
        renderTxImagePreview();
        document.getElementById('txPayerIds').value = '';
        document.getElementById('txExpected').value = '';
        document.getElementById('txPerPerson').value = '';
        openModal('modalTx');
    }

    async function editTx(id) {
        var d = await api('transactions', { id: id, per_page: 1 });
        var t = (d.transactions || [])[0];
        if (!t) return toast('记录不存在', 'error');
        document.getElementById('modalTxTitle').textContent = '编辑收支';
        document.getElementById('txId').value = t.id;
        document.getElementById('txType').value = t.type;
        onTxTypeChange();
        document.getElementById('txSubCategory').value = t.sub_category || '';
        document.getElementById('txSourceInfo').value = t.source_info || '';
        document.getElementById('txAmount').value = t.amount;
        document.getElementById('txDate').value = t.date;
        document.getElementById('txDesc').value = t.description;
        document.getElementById('txCategory').value = t.category;
        document.getElementById('txExpected').value = t.expected_amount || '';
        document.getElementById('txPayerIds').value = t.payer_ids || '';
        txImageList = [];
        // v1.5.6：优先数据库凭证图 ID
        if (t.image_ids) { try { var iids = typeof t.image_ids === 'string' ? JSON.parse(t.image_ids) : t.image_ids; if (Array.isArray(iids)) txImageList = iids.filter(Boolean); } catch (e) {} }
        if (!txImageList.length) {
            if (t.images) { try { var imgs = typeof t.images === 'string' ? JSON.parse(t.images) : t.images; if (Array.isArray(imgs)) txImageList = imgs.filter(Boolean); } catch (e) {} }
            if (!txImageList.length && t.image_path) txImageList = [t.image_path];
        }
        renderTxImagePreview();
        var subVal = document.getElementById('txSubCategory').value;
        document.getElementById('txSourceGroup').style.display = (subVal === '其他来源' ? 'block' : 'none');
        document.getElementById('txRosterGroup').style.display = (subVal === '班费收缴' ? 'block' : 'none');
        document.getElementById('txExpectedGroup').style.display = (subVal === '班费收缴' ? 'block' : 'none');
        document.getElementById('txPerPersonGroup').style.display = (subVal === '班费收缴' ? 'block' : 'none');
        if (subVal === '班费收缴') {
            await loadRosterForTx();
            var pids = t.payer_ids;
            if (pids === 'all') { document.querySelectorAll('.rosterCb').forEach(function (cb) { cb.checked = true; }); }
            else if (pids) { var arr = typeof pids === 'string' ? JSON.parse(pids) : pids; if (Array.isArray(arr)) { document.querySelectorAll('.rosterCb').forEach(function (cb) { cb.checked = arr.includes(parseInt(cb.value)); }); } }
            // 回填每人应缴
            var pp = '';
            if (pids && pids !== 'all') {
                var arr2 = typeof pids === 'string' ? JSON.parse(pids) : pids;
                if (Array.isArray(arr2) && arr2.length) pp = (Number(t.amount) / arr2.length).toFixed(2);
            }
            document.getElementById('txPerPerson').value = pp;
        }
        openModal('modalTx');
    }

    function onTxTypeChange() {
        var t = document.getElementById('txType').value;
        var subs = t === 'income' ? INCOME_SUBS : EXPENSE_SUBS;
        var sel = document.getElementById('txSubCategory');
        sel.innerHTML = Object.entries(subs).map(function (kv) { return '<option value="' + kv[1] + '">' + kv[0] + '</option>'; }).join('');
        var val = sel.value;
        document.getElementById('txSourceGroup').style.display = (val === '其他来源' ? 'block' : 'none');
        document.getElementById('txRosterGroup').style.display = (val === '班费收缴' ? 'block' : 'none');
        document.getElementById('txExpectedGroup').style.display = (val === '班费收缴' ? 'block' : 'none');
        document.getElementById('txPerPersonGroup').style.display = (val === '班费收缴' ? 'block' : 'none');
        if (val === '班费收缴') loadRosterForTx();
    }

    function calcPerPerson() { var t = parseFloat(document.getElementById('txExpected').value) || 0, c = document.querySelectorAll('.rosterCb').length || 1; document.getElementById('txPerPerson').value = (t / c).toFixed(2); }
    function calcExpected() { var p = parseFloat(document.getElementById('txPerPerson').value) || 0, c = document.querySelectorAll('.rosterCb').length || 1; document.getElementById('txExpected').value = (p * c).toFixed(2); }

    async function loadRosterForTx() {
        try { var d = await api('roster'); var list = d.roster || []; document.getElementById('txRosterList').innerHTML = list.map(function (s) { return '<label style="display:flex;align-items:center;gap:4px;font-size:12px;padding:2px 0"><input type="checkbox" value="' + s.id + '" class="rosterCb"> ' + escapeHtml(s.name) + '</label>'; }).join('') || '<span style="font-size:11px;color:var(--text-secondary)">花名册为空，请先导入</span>'; } catch (e) {}
    }

    function selectAllRoster() { document.querySelectorAll('.rosterCb').forEach(function (cb) { cb.checked = true; }); }
    function clearRoster() { document.querySelectorAll('.rosterCb').forEach(function (cb) { cb.checked = false; }); }

    async function saveTransaction() {
        var id = document.getElementById('txId').value;
        var subCat = document.getElementById('txSubCategory').value;
        var srcInfo = document.getElementById('txSourceInfo').value.trim();
        if (subCat === '其他来源' && !srcInfo) return toast('请填写来源信息', 'error');
        // v1.5.6：数字 = 数据库图ID（image_ids），字符串 = 旧版文件路径（images/image_path 兼容）
        var imgIds = txImageList.filter(function (x) { return typeof x === 'number'; });
        var imgPaths = txImageList.filter(function (x) { return typeof x !== 'number'; });
        var payload = { type: document.getElementById('txType').value, sub_category: subCat, source_info: subCat === '其他来源' ? srcInfo : '', amount: parseFloat(document.getElementById('txAmount').value), date: document.getElementById('txDate').value, description: document.getElementById('txDesc').value.trim(), category: document.getElementById('txCategory').value, image_ids: imgIds, images: imgPaths, image_path: imgPaths.length ? imgPaths[0] : '', expected_amount: document.getElementById('txExpected').value || null };
        if (subCat === '班费收缴') { var cbs = document.querySelectorAll('.rosterCb:checked'); if (cbs.length === document.querySelectorAll('.rosterCb').length) payload.payer_ids = 'all'; else if (cbs.length > 0) payload.payer_ids = JSON.stringify(Array.from(cbs).map(function (cb) { return parseInt(cb.value); })); }
        if (!payload.amount || payload.amount <= 0) return toast('无效金额', 'error');
        if (!payload.date) return toast('请选择日期', 'error');
        if (!payload.description) return toast('请输入描述', 'error');
        try { if (id) { payload.id = parseInt(id); await api('transactions&id=' + id, payload, 'PUT'); } else { await api('transactions', payload, 'POST'); } closeModal('modalTx'); renderTransactions(); renderDashboard(); toast(id ? '已更新' : '已添加'); } catch (e) { toast(e.message, 'error'); }
    }

    function deleteTx(id) { if (!confirm('确定删除？')) return; api('transactions&id=' + id, {}, 'DELETE').then(function () { renderTransactions(); renderDashboard(); toast('已删除'); }).catch(function (e) { toast(e.message, 'error'); }); }

    async function uploadTxImages() {
        var files = document.getElementById('txImageFile').files;
        if (!files || !files.length) return toast('请先选择图片', 'error');
        for (var i = 0; i < files.length; i++) {
            var fd = new FormData();
            fd.append('image', files[i]);
            fd.append("csrf_" + "token", _t);
            try {
                var r = await fetch(API + '?action=upload_image', { method: 'POST', body: fd });
                var data = await r.json();
                if (!r.ok) throw new Error(data.error);
                txImageList.push(data.id);
            } catch (e) { toast('第 ' + (i + 1) + ' 张上传失败: ' + e.message, 'error'); }
        }
        document.getElementById('txImageFile').value = '';
        renderTxImagePreview();
        if (txImageList.length) toast('已上传 ' + txImageList.length + ' 张图片');
    }

    function renderTxImagePreview() {
        var box = document.getElementById('txImagePreview');
        if (!box) return;
        if (!txImageList.length) { box.innerHTML = ''; return; }
        box.innerHTML = txImageList.map(function (p, i) {
            return '<div style="display:inline-block;position:relative;margin:4px"><img src="' + escapeHtml(imgUrl(p, true)) + '" style="max-width:120px;max-height:90px;border-radius:6px;border:1px solid var(--border)"><span style="position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;border-radius:50%;width:18px;height:18px;line-height:18px;text-align:center;font-size:11px;cursor:pointer" onclick="window._removeTxImage(' + i + ')">×</span></div>';
        }).join('');
    }

    function removeTxImage(i) { txImageList.splice(i, 1); renderTxImagePreview(); }

    // ========== 导入 ==========
    async function previewXlsx() {
        var file = document.getElementById('xlsxFile').files[0]; if (!file) return toast('请选择xlsx文件', 'error');
        var fd = new FormData(); fd.append('xlsx', file);
        try { var r = await fetch(API + '?action=upload_xlsx', { method: 'POST', body: fd }); var data = await r.json(); if (!r.ok) throw new Error(data.error); xlsxPreviewData = data.preview || []; var c = document.getElementById('importPreview'); if (!xlsxPreviewData.length) { c.innerHTML = '<p style="color:var(--danger);margin-top:12px">⚠️ 未解析到有效数据</p>'; return; } c.innerHTML = '<p style="margin-top:12px;font-weight:600">📋 预览 ' + xlsxPreviewData.length + ' 条</p><div class="table-wrap"><table><thead><tr><th>#</th><th>类型</th><th>子分类</th><th>金额</th><th>日期</th><th>描述</th><th>分类</th></tr></thead><tbody>' + xlsxPreviewData.map(function (t, i) { return '<tr><td>' + (i + 1) + '</td><td><span class="badge badge-' + (t.type === 'income' ? 'income' : 'expense') + '">' + (t.type === 'income' ? '收入' : '支出') + '</span></td><td>' + escapeHtml(t.sub_category || '-') + '</td><td style="font-weight:700;color:' + (t.type === 'income' ? 'var(--success)' : 'var(--danger)') + '">' + (t.type === 'income' ? '+' : '-') + '¥' + (t.amount || 0).toFixed(2) + '</td><td>' + escapeHtml(t.date) + '</td><td>' + escapeHtml(t.description) + '</td><td>' + escapeHtml(t.category) + '</td></tr>'; }).join('') + '</tbody></table></div>'; } catch (e) { toast(e.message, 'error'); }
    }

    async function importXlsx() {
        if (!xlsxPreviewData.length) { await previewXlsx(); if (!xlsxPreviewData.length) return; }
        if (!confirm('确定导入 ' + xlsxPreviewData.length + ' 条？')) return;
        var fd = new FormData(); fd.append('xlsx', document.getElementById('xlsxFile').files[0]); fd.append('do_import', '1'); fd.append("csrf_" + "token", _t);
        try { var r = await fetch(API + '?action=upload_xlsx', { method: 'POST', body: fd }); var data = await r.json(); if (!r.ok) throw new Error(data.error); xlsxPreviewData = []; document.getElementById('xlsxFile').value = ''; document.getElementById('importPreview').innerHTML = ''; renderTransactions(); renderDashboard(); toast('成功导入 ' + data.imported + ' 条'); } catch (e) { toast(e.message, 'error'); }
    }

    function exportTransactions() { var f = document.getElementById('exportDateFrom').value, t = document.getElementById('exportDateTo').value, u = API + '?action=export'; if (f) u += '&date_from=' + f; if (t) u += '&date_to=' + t; window.open(u, '_blank'); }
    function exportUnpaid() { window.open(API + '?action=export_unpaid', '_blank'); }

    // ========== 学生管理 ==========
    async function renderStudents() {
        try { var d = await api('users'); var cm = hasPerm('manageStudents') || hasPerm('manageAllAccounts'), ca = hasPerm('manageAllAccounts'); var html = '<table><thead><tr><th>ID</th><th>姓名</th><th>状态</th><th>角色</th>' + (cm ? '<th>操作</th>' : '') + '</tr></thead><tbody>'; d.users.forEach(function (u) { html += '<tr' + (u.banned ? ' style="background:#fef2f2"' : '') + '><td>#' + u.id + '</td><td>' + escapeHtml(u.username) + (u.highest_role ? ' <span style="font-size:10px;color:var(--primary);font-weight:600">[' + escapeHtml(ROLE_LABELS[u.highest_role] || '') + ']</span>' : '') + '</td><td>' + (u.banned ? '<span class="badge badge-expense" title="' + escapeHtml(u.ban_reason || '') + '">已封禁</span>' : '<span class="badge badge-income">正常</span>') + '</td><td><div class="role-tags">' + sortRoles(u.roles || []).map(function (r) { return '<span class="badge badge-role">' + escapeHtml(ROLE_LABELS[r] || r) + '</span>'; }).join('') + '</div></td>'; if (cm) { html += '<td style="white-space:nowrap"><button class="btn btn-outline btn-sm" onclick="window._editStu(' + u.id + ')">✏️</button>'; if (ca && u.id !== (currentUser && currentUser.id)) { if (u.banned) html += '<button class="btn btn-success btn-sm" onclick="window._unbanUser(' + u.id + ')" style="margin-left:4px">解锁</button>'; else html += '<button class="btn btn-danger btn-sm" onclick="window._banUser(' + u.id + ')" style="margin-left:4px">封禁</button><button class="btn btn-danger btn-sm" onclick="window._deleteStu(' + u.id + ')" style="margin-left:4px">🗑️</button>'; } html += '</td>'; } html += '</tr>'; }); html += '</tbody></table>'; document.getElementById('studentTable').innerHTML = html; } catch (e) { toast(e.message, 'error'); }
    }

    function banUser(id) { var r = prompt('请输入封禁理由：'); if (!r || !r.trim()) return; api('ban_user', { user_id: id, ban: 1, reason: r.trim() }, 'POST').then(function () { renderStudents(); toast('已封禁'); }).catch(function (e) { toast(e.message, 'error'); }); }
    function unbanUser(id) { if (!confirm('确定解除封禁？')) return; api('ban_user', { user_id: id, ban: 0 }, 'POST').then(function () { renderStudents(); toast('已解封'); }).catch(function (e) { toast(e.message, 'error'); }); }

    function exportLogsFiltered() {
        var p = [];
        var uid = (document.getElementById('logFilterUser') || {}).value;
        var act = (document.getElementById('logFilterAction') || {}).value;
        if (uid && uid !== '0') p.push('user_id=' + encodeURIComponent(uid));
        if (act) p.push('log_action=' + encodeURIComponent(act));
        window.location.href = 'api.php?action=export_logs' + (p.length ? '&' + p.join('&') : '');
        return false;
    }

    function showAddStudent() { document.getElementById('modalStudentTitle').textContent = '添加用户'; document.getElementById('stuId').value = ''; document.getElementById('stuName').value = ''; document.getElementById('stuPass').value = '123456'; document.querySelectorAll('#stuRoles input').forEach(function (cb) { cb.checked = false; }); document.querySelector('#stuRoles input[value="student"]').checked = true; if (!hasPerm('manageAllAccounts')) { document.querySelectorAll('#stuRoles input[value="head_teacher"], #stuRoles input[value="admin"]').forEach(function (cb) { cb.disabled = true; cb.checked = false; }); } else { document.querySelectorAll('#stuRoles input').forEach(function (cb) { cb.disabled = false; }); } openModal('modalStudent'); }

    async function editStu(id) { var d = await api('users'); var u = (d.users || []).find(function (x) { return x.id == id; }); if (!u) return; document.getElementById('modalStudentTitle').textContent = '编辑用户'; document.getElementById('stuId').value = u.id; document.getElementById('stuName').value = u.username; document.getElementById('stuPass').value = ''; document.querySelectorAll('#stuRoles input').forEach(function (cb) { cb.checked = (u.roles || []).includes(cb.value); }); if (!hasPerm('manageAllAccounts')) { document.querySelectorAll('#stuRoles input[value="head_teacher"], #stuRoles input[value="admin"]').forEach(function (cb) { cb.disabled = true; cb.checked = false; }); } else { document.querySelectorAll('#stuRoles input').forEach(function (cb) { cb.disabled = false; }); } openModal('modalStudent'); }

    async function saveStudent() {
        var id = document.getElementById('stuId').value, username = document.getElementById('stuName').value.trim(), pwd = document.getElementById('stuPass').value, roles = [];
        document.querySelectorAll('#stuRoles input:checked').forEach(function (cb) { roles.push(cb.value); });
        if (!username) return toast('请输入姓名', 'error');
        if (!roles.length) return toast('请选择角色', 'error');
        try { if (id) { var payload = { id: parseInt(id), username: username, roles: roles }; if (pwd) payload["pw" + "d"] = pwd; await api('users&id=' + id, payload, 'PUT'); } else { await api('users', { username: username, pwd: pwd || "123456", roles: roles }, 'POST'); } closeModal('modalStudent'); renderStudents(); toast(id ? '已更新' : '已添加'); } catch (e) { toast(e.message, 'error'); }
    }

    function deleteStu(id) { if (!confirm('确定删除？')) return; api('users&id=' + id, {}, 'DELETE').then(function () { renderStudents(); toast('已删除'); }).catch(function (e) { toast(e.message, 'error'); }); }

    // ========== 花名册 ==========
    async function renderRoster() { try { var d = await api('roster'); var list = d.roster || []; var canDel = hasPerm('deleteRoster'), html = ''; if (list.length === 0) html = '<div class="empty"><div class="icon">📋</div>花名册为空，请先导入</div>'; else { html = '<table><thead><tr><th>序号</th><th>姓名</th>' + (canDel ? '<th>操作</th>' : '') + '</tr></thead><tbody>'; list.forEach(function (s, i) { html += '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(s.name) + '</td>' + (canDel ? '<td><button class="btn btn-danger btn-sm" onclick="window._deleteRoster(' + s.id + ')">🗑️</button></td>' : '') + '</tr>'; }); html += '</tbody></table>'; } document.getElementById('rosterTable').innerHTML = html; } catch (e) { toast(e.message, 'error'); } }

    function showRosterImport() { var n = prompt('请输入学生姓名，每行一人：'); if (!n || !n.trim()) return; var l = n.split(/[\r\n]+/).map(function (s) { return s.trim(); }).filter(function (s) { return s; }); if (!l.length) return; api('roster', { names: l }, 'POST').then(function (d) { toast('已导入 ' + d.imported + ' 人'); renderRoster(); }).catch(function (e) { toast(e.message, 'error'); }); }

    async function previewRosterXlsx() { var f = document.getElementById('rosterXlsxFile').files[0]; if (!f) return toast('请选择 xlsx 文件', 'error'); var fd = new FormData(); fd.append('xlsx', f); try { var r = await fetch(API + '?action=roster_xlsx', { method: 'POST', body: fd }); var d = await r.json(); if (!r.ok) throw new Error(d.error); var ns = d.preview || [], c = document.getElementById('rosterImportPreview'); if (!ns.length) { c.innerHTML = '<p style="color:var(--danger);margin-top:12px">⚠️ 未解析到有效姓名</p>'; return; } c.innerHTML = '<p style="margin-top:12px;font-weight:600">📋 预览 ' + ns.length + ' 人</p><div class="table-wrap"><table><thead><tr><th>#</th><th>姓名</th></tr></thead><tbody>' + ns.map(function (n, i) { return '<tr><td>' + (i + 1) + '</td><td>' + escapeHtml(n) + '</td></tr>'; }).join('') + '</tbody></table></div>'; window._rosterXlsxData = ns; } catch (e) { toast(e.message, 'error'); } }

    async function importRosterXlsx() { var ns = window._rosterXlsxData; if (!ns || !ns.length) { await previewRosterXlsx(); ns = window._rosterXlsxData; if (!ns || !ns.length) return; } if (!confirm('确定导入 ' + ns.length + ' 人？')) return; var fd = new FormData(); fd.append('xlsx', document.getElementById('rosterXlsxFile').files[0]); fd.append('do_import', '1'); fd.append("csrf_" + "token", _t); try { var r = await fetch(API + '?action=roster_xlsx', { method: 'POST', body: fd }); var d = await r.json(); if (!r.ok) throw new Error(d.error); window._rosterXlsxData = null; document.getElementById('rosterXlsxFile').value = ''; document.getElementById('rosterImportPreview').innerHTML = ''; renderRoster(); toast('已导入 ' + d.imported + ' 人'); } catch (e) { toast(e.message, 'error'); } }

    function deleteRoster(id) { if (!confirm('确定删除？')) return; api('roster&id=' + id, {}, 'DELETE').then(function () { renderRoster(); toast('已删除'); }).catch(function (e) { toast(e.message, 'error'); }); }

    // ========== 缴费 ==========
    async function renderPayments() { try { var r = await Promise.all([api('payments'), api('expected_payment')]); var d = r[0], ep = r[1]; document.getElementById('perPersonAmt').value = ep.per_person || ''; var ro = ep.roster || [], ne = ro.filter(function (s) { return !s.exempt; }), et = (ep.per_person || 0) * ne.length; document.getElementById('paymentSummary').innerHTML = '<div class="card"><div class="card-label">👥 花名册人数</div><div class="card-value" style="color:#6366f1">' + d.roster_count + '</div></div><div class="card"><div class="card-label">💰 总收款</div><div class="card-value income">¥' + d.total_collected.toFixed(2) + '</div></div><div class="card"><div class="card-label">✅ 已缴纳</div><div class="card-value income">' + d.paid_count + '人</div></div><div class="card"><div class="card-label">⚠️ 未缴纳</div><div class="card-value expense">' + d.unpaid_count + '人</div></div><div class="card"><div class="card-label">🎯 应缴总额</div><div class="card-value" style="color:#8b5cf6">¥' + et.toFixed(2) + '</div><div class="card-sub">' + ne.length + '人 × ¥' + (ep.per_person || 0) + '</div></div>'; var ph = ''; if (d.rounds && d.rounds.length) { d.rounds.forEach(function (rd, i) { ph += '<div style="background:var(--bg-card);border-radius:8px;padding:10px 14px;margin-bottom:6px;box-shadow:var(--shadow)"><b>#' + (i + 1) + ' ' + escapeHtml(rd.date) + '</b> ' + escapeHtml(rd.description) + ' <span style="color:var(--success)">¥' + (rd.amount || 0).toFixed(2) + '</span><br><span style="font-size:11px">已缴' + rd.paid_count + '人: ' + rd.paid_list.map(function (p) { return escapeHtml(p.name); }).join('、') + '</span><br><span style="font-size:11px;color:var(--danger)">未缴' + rd.unpaid_count + '人: ' + rd.unpaid_list.map(function (p) { return escapeHtml(p.name); }).join('、') + '</span></div>'; }); } else { ph = '<div class="empty">暂无缴费记录</div>'; } document.getElementById('paidTable').innerHTML = ph; document.getElementById('rosterPayTable').innerHTML = ro.length ? '<table><thead><tr>' + (hasPerm('manageRoster') ? '<th style="width:28px"><input type="checkbox" onclick="window._toggleAllRoster(this)"></th>' : '') + '<th>姓名</th><th>状态</th>' + (hasPerm('manageRoster') ? '<th>操作</th>' : '') + '</tr></thead><tbody>' + ro.map(function (s) { return '<tr>' + (hasPerm('manageRoster') ? '<td><input type="checkbox" class="rosterPayCb" value="' + s.id + '"></td>' : '') + '<td>' + escapeHtml(s.name) + '</td><td>' + (s.exempt ? '<span class="badge badge-role">免缴</span>' : '<span class="badge badge-income">应缴</span>') + '</td>' + (hasPerm('manageRoster') ? '<td><button class="btn btn-outline btn-sm" onclick="window._toggleExempt(' + s.id + ',' + (s.exempt ? 1 : 0) + ')">' + (s.exempt ? '设为应缴' : '设为免缴') + '</button></td>' : '') + '</tr>'; }).join('') + '</tbody></table>' : '<div class="empty">花名册为空</div>'; document.getElementById('unpaidTable').innerHTML = d.unpaid_list.length === 0 ? '<div class="empty" style="padding:20px;color:var(--success)">🎉 全部已缴！</div>' : '<table><thead><tr><th>姓名</th><th>状态</th></tr></thead><tbody>' + d.unpaid_list.map(function (p) { return '<tr><td>' + escapeHtml(p.name) + '</td><td><span class="badge badge-expense">未缴纳</span></td></tr>'; }).join('') + '</tbody></table>'; } catch (e) { toast(e.message, 'error'); } }

    function _toggleAllTx(el) { var cbs = document.querySelectorAll('.txCb'); for (var i = 0; i < cbs.length; i++) cbs[i].checked = el.checked; }
    function _toggleAllRoster(el) { var cbs = document.querySelectorAll('.rosterPayCb'); for (var i = 0; i < cbs.length; i++) cbs[i].checked = el.checked; }
    async function _batchDeleteTx() {
        if (!confirm('确定批量删除选中的记录？（可在回收站恢复）')) return;
        var ids = [];
        var cbs = document.querySelectorAll('.txCb:checked');
        for (var i = 0; i < cbs.length; i++) ids.push(parseInt(cbs[i].value, 10));
        if (!ids.length) return toast('请先勾选要删除的记录', 'error');
        try {
            var d = await api('transactions_batch', { ids: ids }, 'POST');
            toast('已删除 ' + d.deleted + ' 条记录');
            _renderTxPage(1);
        } catch (e) { toast(e.message, 'error'); }
    }
    async function _batchExempt(val) {
        var ids = [];
        var cbs = document.querySelectorAll('.rosterPayCb:checked');
        for (var i = 0; i < cbs.length; i++) ids.push(parseInt(cbs[i].value, 10));
        if (!ids.length) return toast('请先勾选花名册学生', 'error');
        try {
            await api('expected_payment', { exempt_ids: ids, exempt: val }, 'POST');
            toast(val ? '已批量设为免缴' : '已批量设为应缴');
            renderPayments();
        } catch (e) { toast(e.message, 'error'); }
    }

    async function setPerPerson() { var v = document.getElementById('perPersonAmt').value; if (!v || parseFloat(v) <= 0) return toast('请输入每人应缴金额', 'error'); try { await api('expected_payment', { per_person: parseFloat(v) }, 'POST'); renderPayments(); toast('已保存'); } catch (e) { toast(e.message, 'error'); } }
    async function toggleRosterExempt(id, ex) { try { await api('expected_payment', { exempt_id: id, exempt: ex ? 0 : 1 }, 'POST'); renderPayments(); } catch (e) { toast(e.message, 'error'); } }

    // ========== 回收站 ==========
    async function renderRecycle() { try { var d = await api('recycle_bin'); var list = d.items || [], html = ''; if (list.length === 0) { html = '<div class="empty">📭 回收站为空</div>'; } else { html = '<table><thead><tr><th>ID</th><th>日期</th><th>类型</th><th>金额</th><th>描述</th><th>删除时间</th><th>操作</th></tr></thead><tbody>'; list.forEach(function (t) { html += '<tr><td>#' + t.id + '</td><td>' + escapeHtml(t.date) + '</td><td>' + escapeHtml(t.type) + '</td><td>¥' + Number(t.amount || 0).toFixed(2) + '</td><td>' + escapeHtml(t.description) + '</td><td>' + escapeHtml(t.deleted_at) + '</td><td style="white-space:nowrap"><button class="btn btn-success btn-sm" onclick="window._restoreTx(' + t.id + ')">恢复</button><button class="btn btn-danger btn-sm" onclick="window._permDeleteTx(' + t.id + ')" style="margin-left:4px">永久删除</button></td></tr>'; }); html += '</tbody></table>'; } document.getElementById('recycleTable').innerHTML = html; } catch (e) { toast(e.message, 'error'); } }
    function restoreTx(id) { api('recycle_bin&id=' + id, {}, 'PUT').then(function () { renderRecycle(); renderTransactions(); renderDashboard(); toast('已恢复'); }).catch(function (e) { toast(e.message, 'error'); }); }
    function permDeleteTx(id) { if (!confirm('⚠️ 永久删除后无法恢复，确定？')) return; api('recycle_bin&id=' + id, {}, 'DELETE').then(function () { renderRecycle(); toast('已永久删除'); }).catch(function (e) { toast(e.message, 'error'); }); }

    // ========== 安全分析 ==========
    async function renderSecurity() { try { var d = await api('security_analysis'); document.getElementById('securitySummary').innerHTML = '<div class="card"><div class="card-label">🔍 多账号风险</div><div class="card-value expense">' + d.multi_account.length + '</div></div><div class="card"><div class="card-label">🌍 异地登录</div><div class="card-value" style="color:#f59e0b">' + d.multi_ip.length + '</div></div><div class="card"><div class="card-label">🚨 登录失败</div><div class="card-value expense">' + d.failures.length + '</div></div><div class="card"><div class="card-label">📋 登录记录</div><div class="card-value" style="color:#6366f1">' + d.recent.length + '</div></div>'; document.getElementById('multiAccountTable').innerHTML = d.multi_account.length ? '<table><thead><tr><th>指纹</th><th>账号数</th><th>账号</th></tr></thead><tbody>' + d.multi_account.map(function (r) { return '<tr><td style="font-size:10px">' + escapeHtml((r.fingerprint || '').substring(0, 16)) + '</td><td style="color:var(--danger)">' + r.accounts + '</td><td>' + escapeHtml(r.names) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">✅ 无多账号风险</div>'; document.getElementById('multiIpTable').innerHTML = d.multi_ip.length ? '<table><thead><tr><th>用户</th><th>IP数</th><th>IP列表</th></tr></thead><tbody>' + d.multi_ip.map(function (r) { return '<tr><td>' + escapeHtml(r.username) + '</td><td style="color:#f59e0b">' + r.ip_count + '</td><td style="font-size:10px">' + escapeHtml(r.ips) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">✅ 无异地登录</div>'; document.getElementById('failuresTable').innerHTML = d.failures.length ? '<table><thead><tr><th>用户名</th><th>失败次数</th><th>最后尝试</th></tr></thead><tbody>' + d.failures.map(function (r) { return '<tr><td>' + escapeHtml(r.username) + '</td><td style="color:var(--danger)">' + r.attempts + '</td><td>' + escapeHtml(r.last_attempt) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">✅ 无失败记录</div>'; } catch (e) { toast(e.message, 'error'); } }

    // ========== 操作日志 ==========
    var logsPage = 1;
    async function renderLogs(pg) { pg = pg || 1; logsPage = pg; try { var p = { page: pg, per_page: 15 }, uidE = document.getElementById('logFilterUser'), actE = document.getElementById('logFilterAction'), uid = uidE ? uidE.value : '', act = actE ? actE.value : ''; if (uid && uid !== '0') p.user_id = uid; if (act) p.log_action = act; var d = await api('logs', p); if (d.users) { var sel = document.getElementById('logFilterUser'); sel.innerHTML = '<option value="0">全部用户</option>' + d.users.map(function (u) { return '<option value="' + u.id + '">' + escapeHtml(u.username) + '</option>'; }).join(''); if (uid) sel.value = uid; } var acts = ['login','logout','create_transaction','update_transaction','delete_transaction','import','create_user','update_user','delete_user'], as = document.getElementById('logFilterAction'); if (as && as.options.length <= 1) { as.innerHTML = '<option value="">全部操作</option>' + acts.map(function (a) { return '<option value="' + a + '">' + a + '</option>'; }).join(''); } var html = ''; if ((d.logs || []).length === 0) { html = '<div class="empty"><div class="icon">📭</div>暂无日志</div>'; } else { html = '<table><thead><tr><th>时间</th><th>用户</th><th>操作</th><th>对象</th><th>详情</th><th>IP</th></tr></thead><tbody>'; d.logs.forEach(function (l) { html += '<tr><td style="white-space:nowrap">' + escapeHtml(l.created_at) + '</td><td>' + escapeHtml(l.username) + '</td><td><span class="badge badge-role">' + escapeHtml(l.action) + '</span></td><td>' + escapeHtml(l.target_type) + (l.target_id ? ' #' + l.target_id : '') + '</td><td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(l.details || '') + '">' + escapeHtml(l.details || '-') + '</td><td style="font-size:11px">' + escapeHtml((l.ip_address || '-') + (l.ipv6_address ? ' / ' + l.ipv6_address : '')) + '</td></tr>'; }); html += '</tbody></table>'; } document.getElementById('logsTable').innerHTML = html; var pag = ''; for (var i = 1; i <= d.total_pages; i++) pag += '<button class="' + (i === d.page ? 'active' : '') + '" onclick="window._renderLogs(' + i + ')">' + i + '</button>'; document.getElementById('logsPagination').innerHTML = pag; } catch (e) { toast(e.message, 'error'); } }

    // ========== 远程升级 ==========

    async function checkUpdate() {
        document.getElementById('upgradeStatus').textContent = '⏳ 检查中...';
        document.getElementById('btnUpgrade').style.display = 'none';
        try {
            var d = await api('check_update');
            if (d.hasUpdate) {
                document.getElementById('upgradeStatus').innerHTML = '🆕 发现新版本 <b>' + escapeHtml(d.remote) + '</b>（当前 ' + escapeHtml(d.current) + '）' + (d.notes ? '<br><small>' + escapeHtml(d.notes) + '</small>' : '');
                document.getElementById('btnUpgrade').style.display = '';
                
            } else {
                document.getElementById('upgradeStatus').textContent = '✅ 已是最新版本 ' + d.current;
            }
        } catch (e) {
            document.getElementById('upgradeStatus').textContent = '❌ ' + e.message;
        }
    }

    async function doUpgrade() {
        
        if (!confirm('⚠️ 升级将覆盖当前文件，已自动备份。确定继续？')) return;
        document.getElementById('upgradeStatus').textContent = '⏳ 升级中，请勿关闭页面...';
        document.getElementById('btnUpgrade').disabled = true;
        try {
            var d = await api('do_upgrade', {}, 'POST');
            document.getElementById('upgradeStatus').textContent = '🎉 ' + (d.message || d.warning || '升级完成');
            document.getElementById('btnUpgrade').style.display = 'none';
            if (d.warning) toast(d.warning, 'warning');
        } catch (e) {
            document.getElementById('upgradeStatus').textContent = '❌ ' + e.message;
            document.getElementById('btnUpgrade').disabled = false;
        }
    }

    // ========== 事件绑定 ==========
    document.querySelectorAll('.modal-overlay').forEach(function (o) { o.addEventListener('click', function (e) { if (e.target === this) this.classList.remove('active'); }); });
    document.getElementById('txSubCategory').addEventListener('change', function () { document.getElementById('txSourceGroup').style.display = (this.value === '其他来源' ? 'block' : 'none'); document.getElementById('txRosterGroup').style.display = (this.value === '班费收缴' ? 'block' : 'none'); document.getElementById('txExpectedGroup').style.display = (this.value === '班费收缴' ? 'block' : 'none'); document.getElementById('txPerPersonGroup').style.display = (this.value === '班费收缴' ? 'block' : 'none'); if (this.value === '班费收缴') loadRosterForTx(); });

    // ========== 初始化 ==========
    var themeBtn = document.getElementById('themeBtn');
    if (themeBtn) { themeBtn.textContent = document.documentElement.classList.contains('dark') ? '\u2600\uFE0F' : '\uD83C\uDF19'; }
    if (window._initialUser) { currentUser = window._initialUser; if (currentUser.roles) currentUser.roles = sortRoles(currentUser.roles); _t = window['_initial' + 'Csrf'] || ''; }
    refreshUI();

    // ========== 全局导出 ==========
    window._editTx = editTx; window._deleteTx = deleteTx; window._editStu = editStu; window._deleteStu = deleteStu;
    window._banUser = banUser; window._unbanUser = unbanUser; window._deleteRoster = deleteRoster;
    window._restoreTx = restoreTx; window._permDeleteTx = permDeleteTx; window._toggleExempt = toggleRosterExempt;
    window._renderTxPage = renderTransactions; window._renderLogs = renderLogs;
    window.doLogin = doLogin; window.doGuestLogin = doGuestLogin; window.doLogout = doLogout;
    window.toggleTheme = toggleTheme;
    window.showChangePassword = _f1; window.changePassword = _f2;
    window.switchPage = switchPage; window.showAddTransaction = showAddTransaction;
    window.toggleSidebar = toggleSidebar;
    window.onTxTypeChange = onTxTypeChange; window.calcPerPerson = calcPerPerson; window.calcExpected = calcExpected;
    window.selectAllRoster = selectAllRoster; window.clearRoster = clearRoster;
    window.saveTransaction = saveTransaction; window.uploadTxImages = uploadTxImages; window.removeTxImage = removeTxImage;
    window._createSemester = _createSemester; window._archiveSemester = _archiveSemester;
    window._receipt = function (id) { window.open('api.php?action=receipt&id=' + id, '_blank', 'width=820,height=760'); };
    window.previewXlsx = previewXlsx; window.importXlsx = importXlsx;
    window.exportTransactions = exportTransactions; window.exportUnpaid = exportUnpaid;
    window.showAddStudent = showAddStudent; window.saveStudent = saveStudent;
    window.showRosterImport = showRosterImport;
    window.previewRosterXlsx = previewRosterXlsx; window.importRosterXlsx = importRosterXlsx;
    window.setPerPerson = setPerPerson; window.closeModal = closeModal; window.refreshUI = refreshUI;
    window.checkUpdate = checkUpdate; window.doUpgrade = doUpgrade; window.renderRecycle = renderRecycle; window.renderSecurity = renderSecurity; window.renderPayments = renderPayments;
    window.renderReport = renderReport;
    window.downloadReport = downloadReport;
    window._toggleAllTx = _toggleAllTx; window._toggleAllRoster = _toggleAllRoster;
    window._batchDeleteTx = _batchDeleteTx; window._batchExempt = _batchExempt;
    window.exportLogsFiltered = exportLogsFiltered;
})();
