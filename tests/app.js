const elements = {
    APIBase: document.querySelector('#APIBase'),
    username: document.querySelector('#username'),
    password: document.querySelector('#password'),
    limit: document.querySelector('#limit'),
    offset: document.querySelector('#offset'),
    logDate: document.querySelector('#logDate'),
    rateLimitIP: document.querySelector('#rateLimitIP'),
    emailMailer: document.querySelector('#emailMailer'),
    emailTo: document.querySelector('#emailTo'),
    emailSubject: document.querySelector('#emailSubject'),
    emailBody: document.querySelector('#emailBody'),
    emailReplyTo: document.querySelector('#emailReplyTo'),
    uploadFile: document.querySelector('#uploadFile'),
    output: document.querySelector('#output'),
    authStatus: document.querySelector('#authStatus'),
    tokenPreview: document.querySelector('#tokenPreview'),
    flowStatus: document.querySelector('#flowStatus'),
};

const testsPathIndex = location.pathname.indexOf('/tests');
const projectBase = testsPathIndex >= 0
    ? location.pathname.slice(0, testsPathIndex)
    : '';

elements.APIBase.value = `${location.origin}${projectBase}/api/v1`;
elements.logDate.value = new Date().toLocaleDateString('en-CA');

let accessToken = '';

function setOutput(value) {
    elements.output.textContent = typeof value === 'string'
        ? value
        : JSON.stringify(value, null, 2);
}

function appendOutput(value) {
    const line = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
    elements.output.textContent += `\n${line}`;
    elements.output.scrollTop = elements.output.scrollHeight;
}

function setToken(token) {
    accessToken = token || '';
    elements.authStatus.textContent = accessToken ? 'Authenticated' : 'Not authenticated';
    elements.authStatus.className = `status ${accessToken ? 'ok' : ''}`;
    elements.tokenPreview.textContent = accessToken
        ? `${accessToken.slice(0, 45)}…`
        : '';
}

function pageQuery() {
    const limit = Number(elements.limit.value || 5);
    const offset = Number(elements.offset.value || 0);
    return `limit=${limit}&offset=${offset}`;
}

function datedPageQuery() {
    return `${pageQuery()}&date=${encodeURIComponent(elements.logDate.value)}`;
}

async function requestAPI(path, options = {}) {
    const headers = {Accept: 'application/json', ...(options.headers || {})};
    if (accessToken && options.auth !== false) {
        headers.Authorization = `Bearer ${accessToken}`;
    }
    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(`${elements.APIBase.value.replace(/\/$/, '')}${path}`, {
        method: options.method || 'GET',
        headers,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const text = await response.text();
    let payload;
    try {
        payload = text === '' ? null : JSON.parse(text);
    } catch {
        payload = text;
    }

    return {
        ok: response.ok,
        status: response.status,
        headers: {
            limit: response.headers.get('X-RateLimit-Limit'),
            remaining: response.headers.get('X-RateLimit-Remaining'),
            reset: response.headers.get('X-RateLimit-Reset'),
            retryAfter: response.headers.get('Retry-After'),
        },
        payload,
    };
}

async function displayRequest(path, options = {}) {
    const result = await requestAPI(path, options);
    setOutput(result);
    return result;
}

async function uploadFile() {
    const file = elements.uploadFile.files[0];
    if (!file) {
        throw new Error('Select a file first.');
    }

    const formData = new FormData();
    formData.append('file', file);
    const headers = {Accept: 'application/json'};
    if (accessToken) headers.Authorization = `Bearer ${accessToken}`;

    const response = await fetch(`${elements.APIBase.value.replace(/\/$/, '')}/files/upload`, {
        method: 'POST',
        headers,
        body: formData,
    });
    const result = {
        ok: response.ok,
        status: response.status,
        payload: await response.json(),
    };
    setOutput(result);
    return result;
}

async function downloadBackup() {
    const headers = {Accept: 'application/zip, application/json'};
    if (accessToken) {
        headers.Authorization = `Bearer ${accessToken}`;
    }

    const response = await fetch(`${elements.APIBase.value.replace(/\/$/, '')}/database-backups/download`, {
        method: 'GET',
        headers,
    });

    if (!response.ok) {
        const text = await response.text();
        let payload;
        try {
            payload = text === '' ? null : JSON.parse(text);
        } catch {
            payload = text;
        }
        setOutput({ok: false, status: response.status, payload});
        return {ok: false, status: response.status, payload};
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const matched = disposition.match(/filename="([^"]+)"/);
    const filename = matched?.[1] || 'database-backup.zip';
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
    const result = {ok: true, status: response.status, filename, size: blob.size};
    setOutput(result);
    return result;
}

async function login() {
    const result = await requestAPI('/auth/login', {
        method: 'POST',
        auth: false,
        body: {
            username: elements.username.value,
            password: elements.password.value,
        },
    });
    const token = result.payload?.data?.token;
    setToken(token || '');
    setOutput(result);
    return result;
}

function requiredId(selector, label) {
    const value = Number(document.querySelector(selector).value);
    if (!Number.isInteger(value) || value < 1) {
        throw new Error(`${label} must be a positive integer.`);
    }
    return value;
}

const actions = {
    'server-health': () => displayRequest('/health/server', {auth: false}),
    'database-health': () => displayRequest('/health/database', {auth: false}),
    login,
    me: () => displayRequest('/auth/me'),
    refresh: async () => {
        const result = await displayRequest('/auth/refresh', {method: 'POST'});
        if (result.payload?.data?.token) setToken(result.payload.data.token);
    },
    logout: async () => {
        const result = await displayRequest('/auth/logout', {method: 'POST'});
        if (result.ok) setToken('');
    },
    roles: () => displayRequest(`/roles?${pageQuery()}`),
    permissions: () => displayRequest(`/permissions?${pageQuery()}`),
    users: () => displayRequest(`/users?${pageQuery()}`),
    activities: () => displayRequest(`/activity-logs?${datedPageQuery()}`),
    'server-logs': () => displayRequest(`/server-logs?${datedPageQuery()}`),
    'rate-limit-blocked': () => displayRequest(`/rate-limits/blocked?${pageQuery()}`),
    'rate-limit-status': () => displayRequest(
        `/rate-limits/status?ip=${encodeURIComponent(elements.rateLimitIP.value)}`,
    ),
    'rate-limit-block': () => displayRequest('/rate-limits/block', {
        method: 'POST',
        body: {ip: elements.rateLimitIP.value},
    }),
    'rate-limit-clear': () => displayRequest('/rate-limits/clear', {
        method: 'POST',
        body: {ip: elements.rateLimitIP.value},
    }),
    'email-logs': () => displayRequest(`/emails?${datedPageQuery()}`),
    'send-email': () => displayRequest('/emails/send', {
        method: 'POST',
        body: {
            mailer: elements.emailMailer.value,
            to: elements.emailTo.value,
            subject: elements.emailSubject.value,
            body: elements.emailBody.value,
            html: true,
            ...(elements.emailReplyTo.value ? {reply_to: elements.emailReplyTo.value} : {}),
        },
    }),
    'upload-file': uploadFile,
    'download-backup': downloadBackup,
    'create-role': async () => {
        const result = await displayRequest('/roles', {
            method: 'POST',
            body: {
                name: document.querySelector('#roleName').value,
                slug: document.querySelector('#roleSlug').value,
            },
        });
        if (result.payload?.data?.id) document.querySelector('#roleId').value = result.payload.data.id;
    },
    'create-permission': async () => {
        const result = await displayRequest('/permissions', {
            method: 'POST',
            body: {
                name: document.querySelector('#permissionName').value,
                slug: document.querySelector('#permissionSlug').value,
            },
        });
        if (result.payload?.data?.id) document.querySelector('#permissionId').value = result.payload.data.id;
    },
    'attach-permission': () => displayRequest(
        `/roles/${requiredId('#roleId', 'Role ID')}/permissions/${requiredId('#permissionId', 'Permission ID')}`,
        {method: 'POST'},
    ),
    'role-permissions': () => displayRequest(
        `/roles/${requiredId('#roleId', 'Role ID')}/permissions?${pageQuery()}`,
    ),
    'assign-role': () => displayRequest(
        `/users/${requiredId('#userId', 'User ID')}/role`,
        {method: 'PUT', body: {role_id: requiredId('#roleId', 'Role ID')}},
    ),
    'user-permissions': () => displayRequest(
        `/users/${requiredId('#userId', 'User ID')}/permissions?${pageQuery()}`,
    ),
    'create-activity': () => displayRequest('/activity-logs', {
        method: 'POST',
        body: {
            description: document.querySelector('#activityDescription').value,
        },
    }),
    'custom-request': () => {
        const method = document.querySelector('#customMethod').value;
        const path = document.querySelector('#customPath').value;
        const rawBody = document.querySelector('#customBody').value.trim();
        const body = ['GET', 'DELETE'].includes(method) || rawBody === '' ? undefined : JSON.parse(rawBody);
        return displayRequest(path.startsWith('/') ? path : `/${path}`, {method, body});
    },
    'full-flow': runFullFlow,
};

document.addEventListener('click', async event => {
    const button = event.target.closest('[data-action]');
    if (!button) return;

    button.disabled = true;
    try {
        await actions[button.dataset.action]();
    } catch (error) {
        setOutput({error: error.message});
    } finally {
        button.disabled = false;
    }
});

const wait = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

async function flowStep(label, path, options = {}, expected = [200]) {
    await wait(175);
    const result = await requestAPI(path, options);
    const passed = expected.includes(result.status);
    appendOutput(`${passed ? 'PASS' : 'FAIL'} ${label} [${result.status}]`);
    if (!passed) {
        appendOutput(result);
        throw new Error(`${label} failed with HTTP ${result.status}`);
    }
    return result;
}

async function runFullFlow() {
    const button = document.querySelector('#runFlow');
    const stamp = Date.now();
    let roleId;
    let permissionId;
    let userId;

    button.disabled = true;
    elements.flowStatus.textContent = 'Running';
    elements.flowStatus.className = 'status';
    setOutput('Starting complete API flow…');

    try {
        await flowStep('server health', '/health/server', {auth: false});
        await flowStep('database health', '/health/database', {auth: false});

        const loginResult = await flowStep('login', '/auth/login', {
            method: 'POST',
            auth: false,
            body: {username: elements.username.value, password: elements.password.value},
        });
        setToken(loginResult.payload.data.token);
        await flowStep('current user', '/auth/me');

        const role = await flowStep('create role', '/roles', {
            method: 'POST',
            body: {name: `Test Role ${stamp}`, slug: `test-role-${stamp}`},
        }, [201]);
        roleId = role.payload.data.id;

        await flowStep('update role', `/roles/${roleId}`, {
            method: 'PATCH',
            body: {name: `Updated Test Role ${stamp}`},
        });

        const permission = await flowStep('create permission', '/permissions', {
            method: 'POST',
            body: {name: `Test permission ${stamp}`, slug: `tests.permission.${stamp}`},
        }, [201]);
        permissionId = permission.payload.data.id;

        await flowStep('attach permission', `/roles/${roleId}/permissions/${permissionId}`, {method: 'POST'});
        await flowStep('paginated role permissions', `/roles/${roleId}/permissions?limit=5&offset=0`);

        const user = await flowStep('create user', '/users', {
            method: 'POST',
            body: {
                name: `Test User ${stamp}`,
                username: `test_user_${stamp}`,
                password: 'password123',
                role_id: roleId,
                is_active: true,
            },
        }, [201]);
        userId = user.payload.data.id;

        await flowStep('assign user role', `/users/${userId}/role`, {
            method: 'PUT',
            body: {role_id: roleId},
        });
        await flowStep('paginated user permissions', `/users/${userId}/permissions?limit=5&offset=0`);
        await flowStep('paginated roles', '/roles?limit=2&offset=0');
        await flowStep('paginated permissions', '/permissions?limit=2&offset=0');
        await flowStep('paginated users', '/users?limit=2&offset=0');

        await flowStep('manual activity', '/activity-logs', {
            method: 'POST',
            body: {
                description: `${loginResult.payload.data.user.name} completed the API test flow`,
            },
        }, [201]);
        await flowStep('paginated activity logs', '/activity-logs?limit=10&offset=0');
        await flowStep('dated server logs', `/server-logs?limit=10&offset=0&date=${elements.logDate.value}`);
        await flowStep('dated email logs', `/emails?limit=10&offset=0&date=${elements.logDate.value}`);
        await flowStep('rate-limit status', `/rate-limits/status?ip=${encodeURIComponent(elements.rateLimitIP.value)}`);
        await flowStep('rate-limit block', '/rate-limits/block', {
            method: 'POST',
            body: {ip: elements.rateLimitIP.value},
        });
        await flowStep('blocked IP list', '/rate-limits/blocked?limit=10&offset=0');
        await flowStep('rate-limit clear', '/rate-limits/clear', {
            method: 'POST',
            body: {ip: elements.rateLimitIP.value},
        });

        await flowStep('delete temporary user', `/users/${userId}`, {method: 'DELETE'});
        userId = null;
        await flowStep('detach permission', `/roles/${roleId}/permissions/${permissionId}`, {method: 'DELETE'});
        await flowStep('delete temporary permission', `/permissions/${permissionId}`, {method: 'DELETE'});
        permissionId = null;
        await flowStep('delete temporary role', `/roles/${roleId}`, {method: 'DELETE'});
        roleId = null;

        const oldToken = accessToken;
        await flowStep('logout', '/auth/logout', {method: 'POST'});
        const rejected = await flowStep('old token rejected', '/auth/me', {
            headers: {Authorization: `Bearer ${oldToken}`},
        }, [401]);
        if (rejected.status === 401) setToken('');

        elements.flowStatus.textContent = 'All tests passed';
        elements.flowStatus.className = 'status ok';
    } catch (error) {
        elements.flowStatus.textContent = error.message;
        elements.flowStatus.className = 'status error';
    } finally {
        button.disabled = false;
    }
}
