<?php
/**
 * 班级班费管理系统 - 前端入口
 */
require_once __DIR__ . '/config.php';

// 禁止缓存（防止 CloudFlare 缓存导致主题不更新）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 未安装时跳转安装向导
if (!isDbInstalled()) {
    if (strpos($_SERVER['SCRIPT_NAME'], 'install.php') === false) {
        header('Location: install.php');
        exit;
    }
}

autoMigrate();
// 未登录访客不启动 session：避免每次响应都 Set-Cookie: PHPSESSID，
// 否则 Cloudflare 无法缓存登录页（配合 CF Cache Rules 缓存无 cookie 请求时生效）
if (session_status() === PHP_SESSION_NONE && !empty($_COOKIE[session_name()])) {
    startSession();
}
$loggedIn = isset($_SESSION['user_id']);

// 确保 CSRF token 已生成（兼容旧 session）
if ($loggedIn) generateCsrfToken();

// 获取权限 JSON 供前端使用
$permsJson = defined('PERMISSIONS') ? PERMISSIONS : '{}';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📒 班级班费管理系统</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📒</text></svg>">
    <!-- Chart.js 本地化（原 jsdelivr CDN 国内访问慢，改为本地走 Cloudflare 优选 IP）；defer 不阻塞首屏渲染 -->
    <script src="assets/vendor/chart.umd.min.js?v=8" defer data-cfasync="false"></script>
    <!-- 主题：localStorage 控制，防闪烁 -->
    <script data-cfasync="false">window.__cfRLUnblockHandlers = true;(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})()</script>
    <!-- 样式表 -->
    <link rel="stylesheet" href="assets/css/style.css?v=8">
    </head>
<body>
    <!-- 加载提示 -->
    <div id="loadingHint" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);font-size:16px;color:#64748b;z-index:1;text-align:center;display:none">
        ⏳ 加载中...<br><small style="font-size:11px">如长时间白屏请刷新</small>
    </div>

    <!-- ========== 登录页面 ========== -->
    <div class="login-overlay" id="loginScreen">
        <div class="login-card">
            <h1>📒 班级班费管理系统</h1>
            <button class="btn-guest" onclick="doGuestLogin()">
                <span class="wave">👋</span> 我是同学，直接进入
            </button>
            <p style="text-align:center;font-size:11px;color:var(--text-secondary);margin-top:6px">
                以访客身份查看班费收支，无需账号
            </p>
            <div class="login-divider">管理员 / 班委登录</div>
            <div class="form-group">
                <label>用户名</label>
                <input type="text" id="loginUser" placeholder="请输入用户名" autocomplete="username">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" id="loginPass" placeholder="请输入密码" autocomplete="current-password">
            </div>
            <button class="btn-login" onclick="doLogin()">🔐 登录</button>
            <p class="hint" id="loginError" style="color:var(--danger);display:none"></p>
            <p class="hint" id="loginDebug" style="color:#f59e0b;display:none;margin-top:4px;font-size:11px"></p>
            <p class="hint">💡 账号由管理员添加，不支持自行注册</p>
        </div>
    </div>

    <!-- ========== 主应用 ========== -->
    <div class="app<?php echo $loggedIn ? ' active' : ''; ?>" id="app">
        <!-- 侧边栏 -->
        <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar(true)" aria-label="打开菜单">☰</button>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>
        <aside class="sidebar" id="mainSidebar">
            <div class="sidebar-header">
                <h2>📒 班费管理</h2>
                <div class="user-info" id="sidebarUser">
                    <?php echo $loggedIn ? htmlspecialchars($_SESSION['username']) : '未登录'; ?>
                </div>
                <button class="mobile-logout" onclick="doLogout()">🚪</button>
                <button class="theme-toggle" id="themeBtn" onclick="toggleTheme()">🌙</button>
            </div>
            <nav class="sidebar-nav">
                <button class="active" data-page="dashboard" onclick="switchPage('dashboard')">
                    <span class="icon">📊</span> 仪表盘
                </button>
                <button data-page="transactions" onclick="switchPage('transactions')">
                    <span class="icon">💰</span> 收支记录
                </button>
                <button data-page="report" onclick="switchPage('report')" id="navReport">
                    <span class="icon">📈</span> 学期报表
                </button>
                <button data-page="import" onclick="switchPage('import')" id="navImport">
                    <span class="icon">📥</span> 导入数据
                </button>
                <button data-page="students" onclick="switchPage('students')" id="navStudents">
                    <span class="icon">👥</span> 用户管理
                </button>
                <button data-page="roster" onclick="switchPage('roster')" id="navRoster">
                    <span class="icon">📋</span> 花名册
                </button>
                <button data-page="payments" onclick="switchPage('payments')" id="navPayments">
                    <span class="icon">💳</span> 缴费情况
                </button>
                <button data-page="logs" onclick="switchPage('logs')" id="navLogs">
                    <span class="icon">📜</span> 操作日志
                </button>
                <button data-page="security" onclick="switchPage('security')" id="navSecurity">
                    <span class="icon">🛡️</span> 安全分析
                </button>
                <button data-page="recycle" onclick="switchPage('recycle')" id="navRecycle">
                    <span class="icon">🗑️</span> 回收站
                </button>
            </nav>
            <div class="sidebar-footer">
                <button class="btn-pwd" onclick="showChangePassword()">🔑 修改密码</button>
                <button class="btn-logout" onclick="doLogout()">🚪 退出登录</button>
            </div>
        </aside>

        <!-- 主内容区 -->
        <main class="main">
            <!-- 仪表盘 -->
            <div class="page active" id="page-dashboard">
                <div class="cards" id="dashboardCards"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;max-width:860px">
                    <div style="background:var(--bg-card);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)">
                        <h4 style="font-size:12px;margin-bottom:6px">📈 月度收支趋势</h4>
                        <canvas id="chartTrend" height="130"></canvas>
                    </div>
                    <div style="background:var(--bg-card);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)">
                        <h4 style="font-size:12px;margin-bottom:6px">🍩 支出分类占比</h4>
                        <canvas id="chartPie" height="130"></canvas>
                    </div>
                </div>
                <div class="section-header"><h3>📋 最近收支记录</h3></div>
                <div class="table-wrap" id="dashboardRecent"></div>
            </div>

            <!-- 学期报表 -->
            <div class="page" id="page-report">
                <div class="section-header">
                    <h3>📈 学期汇总报表</h3>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <select id="reportYear" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)" onchange="renderReport()"></select>
                        <select id="reportSemester" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)" onchange="renderReport()">
                            <option value="spring">春季学期</option>
                            <option value="autumn">秋季学期</option>
                        </select>
                        <button class="btn btn-primary btn-sm" onclick="renderReport()">🔍 生成报表</button>
                        <button class="btn btn-outline btn-sm" onclick="downloadReport()">📥 下载 Excel</button>
                        <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ 打印</button>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;max-width:860px">
                    <div style="background:var(--bg-card);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)">
                        <h4 style="font-size:12px;margin-bottom:6px">📊 月度收支对比</h4>
                        <canvas id="reportChartBar" height="130"></canvas>
                    </div>
                    <div style="background:var(--bg-card);border-radius:var(--radius);padding:10px 12px;box-shadow:var(--shadow)">
                        <h4 style="font-size:12px;margin-bottom:6px">🍩 支出分类占比</h4>
                        <canvas id="reportChartPie" height="130"></canvas>
                    </div>
                </div>
                <div id="reportContent"></div>
                <div style="margin-top:24px;background:var(--bg-card);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow)">
                    <h4 style="font-size:14px;margin-bottom:10px">📚 学期管理</h4>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
                        <input type="text" id="semName" placeholder="学期名称，如：2025秋季学期" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:180px;background:var(--bg-card);color:var(--text)">
                        <input type="date" id="semStart" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)">
                        <span style="font-size:12px;color:var(--text-secondary)">至</span>
                        <input type="date" id="semEnd" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text)">
                        <button class="btn btn-primary btn-sm" onclick="window._createSemester()">➕ 新建学期</button>
                        <span style="font-size:11px;color:var(--text-secondary)">期末后归档当前学期，即为「结转」</span>
                    </div>
                    <div id="semesterTable"></div>
                </div>
            </div>

            <!-- 收支记录 -->
            <div class="page" id="page-transactions">
                <div class="section-header">
                    <h3>💰 收支明细</h3>
                    <div class="toolbar">
                        <select id="filterType" onchange="window._renderTxPage(1)">
                            <option value="">全部</option>
                            <option value="income">收入</option>
                            <option value="expense">支出</option>
                        </select>
                        <input type="month" id="filterMonth" onchange="window._renderTxPage(1)">
                        <input type="text" id="filterSearch" placeholder="搜索..." oninput="window._renderTxPage(1)">
                        <button class="btn btn-danger btn-sm" onclick="window._batchDeleteTx()" id="btnBatchDel" style="display:none">🗑️ 批量删除</button>
                        <button class="btn btn-primary" onclick="showAddTransaction()" id="btnAddTx" style="display:none">+ 添加</button>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
                    <span style="font-size:12px;color:var(--text-secondary)">下载表格：</span>
                    <input type="date" id="exportDateFrom" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;width:auto" title="开始日期">
                    <span style="font-size:12px;color:var(--text-secondary)">至</span>
                    <input type="date" id="exportDateTo" style="padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;width:auto" title="结束日期">
                    <button class="btn btn-outline btn-sm" onclick="exportTransactions()">📥 下载表格</button>
                </div>
                <div class="table-wrap" id="transactionTable"></div>
                <div class="pagination" id="transactionPagination"></div>
            </div>

            <!-- 导入数据 -->
            <div class="page" id="page-import">
                <div class="section-header">
                    <h3>📥 导入收支数据</h3>
                    <a href="api.php?action=download_template" class="btn btn-outline btn-sm" style="text-decoration:none">📥 下载模板</a>
                </div>
                <div class="import-area">
                    <p style="font-weight:600;margin-bottom:12px">上传表格文件（.xlsx）</p>
                    <div style="display:flex;gap:8px;align-items:center">
                        <input type="file" id="xlsxFile" accept=".xlsx" style="flex:1;min-width:150px;padding:8px;border:1.5px solid var(--border);border-radius:6px;font-size:13px">
                        <button class="btn btn-primary btn-sm" onclick="previewXlsx()">🔍 预览</button>
                        <button class="btn btn-success btn-sm" onclick="importXlsx()">✅ 导入</button>
                    </div>
                    <div id="importPreview"></div>
                </div>
            </div>

            <!-- 学生管理 -->
            <div class="page" id="page-students">
                <div class="section-header">
                    <h3>👥 用户管理</h3>
                    <button class="btn btn-primary" onclick="showAddStudent()">+ 添加</button>
                </div>
                <div class="table-wrap" id="studentTable"></div>
            </div>

            <!-- 花名册 -->
            <div class="page" id="page-roster">
                <div class="section-header">
                    <h3>📋 班级花名册</h3>
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-primary btn-sm" onclick="showRosterImport()">📥 录入名单</button>
                        <a href="api.php?action=roster_template" class="btn btn-outline btn-sm" style="text-decoration:none">📥 下载模板</a>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
                    <input type="file" id="rosterXlsxFile" accept=".xlsx" style="flex:1;min-width:150px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                    <button class="btn btn-primary btn-sm" onclick="previewRosterXlsx()">🔍 预览</button>
                    <button class="btn btn-success btn-sm" onclick="importRosterXlsx()">✅ 导入</button>
                </div>
                <div id="rosterImportPreview"></div>
                <div class="table-wrap" id="rosterTable"></div>
            </div>

            <!-- 缴费情况 -->
            <div class="page" id="page-payments">
                <div class="section-header">
                    <h3>💳 缴费情况总览</h3>
                    <div style="display:flex;gap:8px">
                        <button class="btn btn-outline btn-sm" onclick="renderPayments()">🔄 刷新</button>
                        <button class="btn btn-outline btn-sm" onclick="exportUnpaid()">📥 导出欠费名单</button>
                    </div>
                </div>
                <div style="background:var(--bg-card);border-radius:var(--radius);padding:12px 16px;margin-bottom:12px;box-shadow:var(--shadow);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <span style="font-size:13px;white-space:nowrap">每人应缴：</span>
                    <input type="number" id="perPersonAmt" step="0.01" min="0" style="width:100px;padding:5px 8px;border:1px solid var(--border);border-radius:4px;font-size:13px" placeholder="金额">
                    <button class="btn btn-primary btn-sm" onclick="setPerPerson()">保存</button>
                    <span style="font-size:11px;color:var(--text-secondary)">| 花名册中可设置免缴学生</span>
                </div>
                <div class="cards" id="paymentSummary"></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <div><h4 style="margin-bottom:8px;color:var(--success)">✅ 已缴纳</h4><div class="table-wrap" id="paidTable"></div></div>
                    <div><h4 style="margin-bottom:8px;color:var(--danger)">⚠️ 未缴纳</h4><div class="table-wrap" id="unpaidTable"></div></div>
                </div>
                <h4 style="margin-top:12px;color:var(--text-secondary)">📋 花名册缴费管理</h4>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                    <button class="btn btn-outline btn-sm" onclick="window._batchExempt(1)">🟢 批量设为免缴</button>
                    <button class="btn btn-outline btn-sm" onclick="window._batchExempt(0)">🔴 批量设为应缴</button>
                    <span style="font-size:11px;color:var(--text-secondary)">勾选下方名单后操作</span>
                </div>
                <div class="table-wrap" id="rosterPayTable"></div>
                <h4 style="margin-top:16px;color:var(--primary)">📅 各轮缴费明细</h4>
                <div id="roundsTable"></div>
            </div>

            <!-- 安全分析 -->
            <div class="page" id="page-security">
                <div class="section-header">
                    <h3>🛡️ 安全分析中心</h3>
                    <button class="btn btn-outline btn-sm" onclick="renderSecurity()">🔄 刷新</button>
                </div>
                <div class="cards" id="securitySummary"></div>
                <h4 style="margin-bottom:8px;color:var(--danger)">🔍 同指纹多账号</h4>
                <div class="table-wrap" id="multiAccountTable"></div>
                <h4 style="margin:16px 0 8px;color:#f59e0b">🌍 同账号多IP</h4>
                <div class="table-wrap" id="multiIpTable"></div>
                <h4 style="margin:16px 0 8px;color:var(--danger)">🚨 登录失败统计</h4>
                <div class="table-wrap" id="failuresTable"></div>
                <h4 style="margin:16px 0 8px;color:var(--primary)">🔄 远程升级</h4>
                <div style="background:var(--bg-card);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow);margin-bottom:8px">
                    <button class="btn btn-primary btn-sm" onclick="checkUpdate()">🔍 检查更新</button>
                    <button class="btn btn-success btn-sm" onclick="doUpgrade()" style="margin-left:8px;display:none" id="btnUpgrade">🚀 立即升级</button>
                    <span id="upgradeStatus" style="margin-left:12px;font-size:13px"></span>
                </div>
            </div>

            <!-- 回收站 -->
            <div class="page" id="page-recycle">
                <div class="section-header">
                    <h3>🗑️ 回收站</h3>
                    <button class="btn btn-outline btn-sm" onclick="renderRecycle()">🔄 刷新</button>
                </div>
                <div class="table-wrap" id="recycleTable"></div>
            </div>

            <!-- 操作日志 -->
            <div class="page" id="page-logs">
                <div class="section-header">
                    <h3>📜 操作日志 <small style="color:var(--danger);font-size:12px">（不可修改、不可删除）</small></h3>
                    <div class="toolbar">
                        <select id="logFilterUser" onchange="window._renderLogs(1)"><option value="0">全部用户</option></select>
                        <select id="logFilterAction" onchange="window._renderLogs(1)"><option value="">全部操作</option></select>
                        <a href="api.php?action=export_logs" class="btn btn-outline btn-sm" style="text-decoration:none" onclick="return exportLogsFiltered()">📥 导出 CSV</a>
                    </div>
                </div>
                <div class="table-wrap" id="logsTable"></div>
                <div class="pagination" id="logsPagination"></div>
            </div>
        </main>
    </div>

    <!-- 修改密码弹窗 -->
    <div class="modal-overlay" id="modalPwd">
        <div class="modal" style="width:400px">
            <h3>🔑 修改密码</h3>
            <div class="form-group"><label>旧密码</label><input type="password" id="oldPassword" autocomplete="current-password"></div>
            <div class="form-group"><label>新密码</label><input type="password" id="newPassword" placeholder="至少4位" autocomplete="new-password"></div>
            <div class="btn-row">
                <button class="btn btn-outline" onclick="closeModal('modalPwd')">取消</button>
                <button class="btn btn-primary" onclick="changePassword()">确认修改</button>
            </div>
        </div>
    </div>

    <!-- 收支弹窗 -->
    <div class="modal-overlay" id="modalTx">
        <div class="modal">
            <h3 id="modalTxTitle">添加收支</h3>
            <div class="form-group"><label>类型</label><select id="txType" onchange="onTxTypeChange()"><option value="income">收入</option><option value="expense">支出</option></select></div>
            <div class="form-group" id="txSubCatGroup"><label>子分类</label><select id="txSubCategory"></select></div>
            <div class="form-group" id="txSourceGroup" style="display:none"><label>来源信息</label><input type="text" id="txSourceInfo" placeholder="如：企业赞助、个人捐赠"></div>
            <div class="form-group" id="txRosterGroup" style="display:none">
                <label>缴费学生</label>
                <div style="display:flex;gap:6px;margin-bottom:6px">
                    <button type="button" class="btn btn-outline btn-sm" onclick="selectAllRoster()">✅ 全部缴纳</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="clearRoster()">❌ 清除</button>
                </div>
                <div class="checkbox-group" id="txRosterList" style="max-height:150px;overflow-y:auto;border:1px solid var(--border);border-radius:6px;padding:8px"></div>
            </div>
            <div class="form-group" id="txExpectedGroup" style="display:none"><label>应收总额（元）</label><input type="number" id="txExpected" step="0.01" min="0" placeholder="全班预计总收入" onchange="calcPerPerson()"></div>
            <div class="form-group" id="txPerPersonGroup" style="display:none"><label>每人应缴（元）</label><input type="number" id="txPerPerson" step="0.01" min="0" placeholder="单人应缴金额" onchange="calcExpected()"></div>
            <div class="form-group"><label>金额（元）</label><input type="number" id="txAmount" step="0.01" min="0.01"></div>
            <div class="form-group"><label>日期</label><input type="date" id="txDate"></div>
            <div class="form-group"><label>描述</label><input type="text" id="txDesc" placeholder="简要描述"></div>
            <div class="form-group">
                <label>分类</label>
                <select id="txCategory">
                    <option value="班费">班费</option>
                    <option value="活动费用">活动费用</option>
                    <option value="日常支出">日常支出</option>
                    <option value="学习资料">学习资料</option>
                    <option value="设备采购">设备采购</option>
                    <option value="活动退款">活动退款</option>
                    <option value="其他收入">其他收入</option>
                    <option value="其他支出">其他支出</option>
                </select>
            </div>
            <div class="form-group">
                <label>凭证图片（可选，可多张）</label>
                <div style="display:flex;gap:8px">
                    <input type="file" id="txImageFile" accept="image/*" multiple style="flex:1">
                    <button type="button" class="btn btn-outline btn-sm" onclick="uploadTxImages()">📤 上传</button>
                </div>
                <div id="txImagePreview" style="margin-top:6px"></div>
                <input type="hidden" id="txImages">
            </div>
            <input type="hidden" id="txId">
            <input type="hidden" id="txPayerIds">
            <div class="btn-row">
                <button class="btn btn-outline" onclick="closeModal('modalTx')">取消</button>
                <button class="btn btn-primary" onclick="saveTransaction()">保存</button>
            </div>
        </div>
    </div>

    <!-- 学生弹窗 -->
    <div class="modal-overlay" id="modalStudent">
        <div class="modal">
            <h3 id="modalStudentTitle">添加用户</h3>
            <div class="form-group"><label>姓名</label><input type="text" id="stuName"></div>
            <div class="form-group"><label>密码</label><input type="password" id="stuPass" value="123456"></div>
            <div class="form-group">
                <label>角色</label>
                <div class="checkbox-group" id="stuRoles">
                    <label><input type="checkbox" value="head_teacher"> 班主任</label>
                    <label><input type="checkbox" value="admin"> 管理员</label>
                    <label><input type="checkbox" value="monitor"> 班长</label>
                    <label><input type="checkbox" value="vice_monitor"> 副班长</label>
                    <label><input type="checkbox" value="finance"> 财务委员</label>
                    <label><input type="checkbox" value="student"> 同学</label>
                </div>
            </div>
            <input type="hidden" id="stuId">
            <div class="btn-row">
                <button class="btn btn-outline" onclick="closeModal('modalStudent')">取消</button>
                <button class="btn btn-primary" onclick="saveStudent()">保存</button>
            </div>
        </div>
    </div>

    <!-- 权限数据 -->
    <script id="perms-data" type="application/json" data-cfasync="false"><?php echo $permsJson; ?></script>

    <!-- 当前用户数据 -->
    <?php if ($loggedIn): ?>
    <script data-cfasync="false">
        window._initialUser = <?php echo json_encode([
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'roles' => $_SESSION['roles'] ?? [],
            'is_guest' => $_SESSION['is_guest'] ?? false,
        ], JSON_UNESCAPED_UNICODE); ?>;
        window._initialCsrf = <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>;
    </script>
    <?php endif; ?>

    <!-- 应用脚本 -->
    <script src="assets/js/app.js?v=11" data-cfasync="false"></script>

    <?php if ($loggedIn): ?>
    <script data-cfasync="false">
        document.getElementById('loadingHint').style.display = 'none';
        document.getElementById('loginScreen').style.display = 'none';
        document.querySelector('.app').classList.add('active');
        window.switchPage && window.switchPage('dashboard');
    </script>
    <?php endif; ?>
</body>
</html>
