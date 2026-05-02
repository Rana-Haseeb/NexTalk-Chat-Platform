// ==============================================
// NexTalk — Dashboard Logic (Phase 3)
// Implements SSE, WhatsApp Features, DOM Diffing
// ==============================================

// ─── Helpers ───
function escapeHTML(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function updateSendButtonState() {
    const input = document.getElementById('message-input');
    const sendBtn = document.getElementById('btn-send');
    if (sendBtn) {
        sendBtn.textContent = (input.value.trim() || appState.attachedFile) ? '➤' : '🎤';
    }
}

// App State
let appState = {
    user: null,
    permissions: {},
    conversations: [],
    activeConvId: null,
    messages: [],
    replyToId: null,
    forwardMessageId: null,
    attachedFile: null,
    typingTimeout: null,
    sseSource: null,
    isScrolledUp: false
};

// ─── Initialization ───
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res = await fetch('../api/check_auth.php');
        const data = await res.json();
        
        if (!data.authenticated) {
            window.location.replace('auth.html');
            return;
        }

        appState.user = data.user;
        appState.permissions = data.user.permissions || {};

        // UI Setup
        document.getElementById('user-display-name').textContent = `${data.user.first_name} ${data.user.last_name}`;
        document.getElementById('user-avatar').textContent = data.user.first_name.charAt(0);
        document.getElementById('user-role-badge').textContent = data.user.role;
        document.getElementById('user-role-badge').className = `user-role-badge ${data.user.role}`;
        
        document.getElementById('member-self-name').textContent = `${data.user.first_name} ${data.user.last_name}`;
        document.getElementById('member-self-avatar').textContent = data.user.first_name.charAt(0);

        if (appState.permissions.can_manage_communities) {
            document.getElementById('btn-new-community').style.display = 'block';
            document.getElementById('community-requests-section').style.display = 'block';
            fetchRequests();
        }

        document.getElementById('auth-guard').style.display = 'none';
        document.getElementById('app-shell').style.display = 'flex';

        // Initial Data Load
        await fetchConversations();

        // Start SSE connection
        initSSE();

        // Event listeners
        document.addEventListener('click', closeContextMenu);

        // Shift+Enter = newline, Enter = send
        document.getElementById('message-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Global Escape key handler
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                // Close lightbox
                const lb = document.querySelector('.lightbox-overlay');
                if (lb) { lb.remove(); return; }
                // Close context menu
                closeContextMenu();
                // Close modals
                closeForwardModal();
                closeMembersModal();
                closeModal();
                // Cancel inline UI
                cancelReply();
                cancelAttachment();
                // Restore focus for accessibility
                document.getElementById('message-input')?.focus();
            }
        });

        // Audio mutex via event delegation (prevents listener leak on re-render)
        document.getElementById('messages-container').addEventListener('play', (e) => {
            if (e.target.tagName === 'AUDIO') {
                document.querySelectorAll('audio').forEach(audio => {
                    if (audio !== e.target) audio.pause();
                });
            }
        }, true);

    } catch (e) {
        console.error("Auth check failed:", e);
        // Don't redirect here — the explicit auth check above handles unauthorized users.
        // Redirecting on ANY error causes infinite loops when the issue is unrelated to auth.
        document.getElementById('auth-guard').innerHTML = `
            <div class="guard-content">
                <div class="guard-logo">Nex<span>Talk</span></div>
                <p style="color:#e74c3c;">Connection error. Please refresh the page.</p>
            </div>`;
    }
});

async function handleLogout() {
    await fetch('../api/logout.php');
    window.location.replace('auth.html');
}

// ─── Server-Sent Events (SSE) ───
function initSSE() {
    if (appState.sseSource) appState.sseSource.close();

    const url = new URL('../api/chat/sse.php', window.location.href);
    if (appState.activeConvId) {
        url.searchParams.append('conversation_id', appState.activeConvId);
        const lastMsg = appState.messages[appState.messages.length - 1];
        if (lastMsg) url.searchParams.append('last_message_id', lastMsg.id);
    }

    appState.sseSource = new EventSource(url.href);

    // Heartbeat to keep connection alive
    appState.sseSource.onmessage = (e) => {
        if (e.data === "{}") return; // Keepalive/reconnect signal
        // Re-fetch conversation list to update previews and unread counts
        fetchConversations();
    };

    appState.sseSource.addEventListener('messages', (e) => {
        const newMessages = JSON.parse(e.data);
        if (newMessages.length > 0) {
            // Task 4: Play notification sound for incoming messages from others
            const hasIncoming = newMessages.some(m => m.sender_id != appState.user.id);
            if (hasIncoming) {
                new Audio('https://assets.mixkit.co/active_storage/sfx/2354/2354-preview.mp3').play().catch(e => {});
            }

            appState.messages = [...appState.messages, ...newMessages];
            renderMessages();
            // Tell server we read them
            fetch('../api/chat/manage_conversations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'mark_read', conversation_id: appState.activeConvId })
            });
        }
    });

    appState.sseSource.addEventListener('status', (e) => {
        const updates = JSON.parse(e.data);
        let changed = false;
        updates.forEach(u => {
            const msg = appState.messages.find(m => m.id === u.id);
            if (msg && msg.status !== u.status) {
                msg.status = u.status;
                changed = true;
            }
        });
        if (changed) renderMessages(false); // don't force scroll
    });

    appState.sseSource.addEventListener('typing', (e) => {
        const typers = JSON.parse(e.data);
        const hdrStatus = document.getElementById('chat-header-status');
        if (typers.length > 0) {
            const names = typers.map(t => t.first_name).join(', ');
            hdrStatus.textContent = `${names} ${typers.length > 1 ? 'are' : 'is'} typing...`;
            hdrStatus.className = 'chat-header-status typing';
        } else {
            // Restore original status from active conversation data
            const conv = appState.conversations.find(c => c.id == appState.activeConvId);
            if (conv) updateHeaderStatus(conv);
        }
    });

    // Real-time deletion sync
    appState.sseSource.addEventListener('deletions', (e) => {
        const deletedIds = JSON.parse(e.data);
        if (!deletedIds.length) return;
        let changed = false;
        deletedIds.forEach(id => {
            const msg = appState.messages.find(m => m.id === id);
            if (msg && !msg.deleted_for_all) {
                msg.deleted_for_all = 1;
                msg.content = null;
                msg.media_url = null;
                changed = true;
            }
        });
        if (changed) renderMessages(false);
    });

    appState.sseSource.addEventListener('reconnect', () => {
        appState.sseSource.close();
        setTimeout(initSSE, 1000);
    });

    appState.sseSource.onerror = () => {
        appState.sseSource.close();
        setTimeout(initSSE, 3000); // Backoff
    };
}

// ─── Conversations List ───
async function fetchConversations() {
    try {
        const res = await fetch('../api/chat/manage_conversations.php');
        const data = await res.json();
        if (data.success) {
            appState.conversations = data.conversations;
            renderConversations();
        }
    } catch (e) {
        console.error("Failed to fetch conversations:", e);
    }
}

// Helper: format sidebar timestamp (today = time, older = date)
function formatSidebarTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return d.toLocaleDateString([], { month: 'numeric', day: 'numeric', year: 'numeric' });
}

// Helper: human-friendly date divider labels
function formatDividerDate(dateString) {
    const d = new Date(dateString);
    const now = new Date();
    const yesterday = new Date();
    yesterday.setDate(now.getDate() - 1);

    if (d.toDateString() === now.toDateString()) return 'TODAY';
    if (d.toDateString() === yesterday.toDateString()) return 'YESTERDAY';
    return d.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

function renderConversations() {
    const coms = [], grps = [], dirs = [];
    const searchTerm = document.getElementById('sidebar-search').value.toLowerCase();
    let totalUnread = 0;

    appState.conversations.forEach(c => {
        // Filter by search
        if (searchTerm && !c.name.toLowerCase().includes(searchTerm)) return;

        totalUnread += parseInt(c.unread_count) || 0;

        let icon, title = c.name;
        if (c.type === 'community') { icon = '🌍'; coms.push(c); }
        else if (c.type === 'group') { icon = '👥'; grps.push(c); }
        else { icon = '👤'; dirs.push(c); }

        const timeStr = formatSidebarTime(c.last_message_time);

        c._html = `
            <div class="room-item ${c.id == appState.activeConvId ? 'active' : ''} ${c.my_status === 'pending' ? 'pending' : ''}" 
                 onclick="selectConversation(${c.id})" data-conv-id="${c.id}">
                <div class="room-icon">${icon}</div>
                <div class="room-info">
                    <div class="room-name">${escapeHTML(title)}</div>
                    <div class="room-preview">${c.last_message_preview ? escapeHTML(c.last_message_preview) : (c.my_status === 'pending' ? '<i>Pending Approval</i>' : '<i>No messages yet</i>')}</div>
                </div>
                <div class="room-meta">
                    ${timeStr ? `<div class="room-time">${timeStr}</div>` : ''}
                    ${c.unread_count > 0 ? `<div class="room-unread">${c.unread_count}</div>` : ''}
                </div>
            </div>
        `;
    });

    // DOM Diffing - Only update if changed to prevent scroll jumping
    updateListHTML('list-communities', coms, 'hdr-communities');
    updateListHTML('list-groups', grps, 'hdr-groups');
    updateListHTML('list-directs', dirs, 'hdr-directs');

    // Dynamic browser tab notification
    document.title = totalUnread > 0 ? `(${totalUnread}) NexTalk` : 'NexTalk — Dashboard';
}

function updateListHTML(containerId, items, headerId) {
    const container = document.getElementById(containerId);
    const header = document.getElementById(headerId);
    
    if (items.length === 0) {
        container.innerHTML = '';
        header.style.display = 'none';
        return;
    }
    
    header.style.display = 'block';
    const newHTML = items.map(c => c._html).join('');
    if (container.innerHTML !== newHTML) {
        container.innerHTML = newHTML;
    }
}

function filterConversations() {
    renderConversations();
}

// ─── Header Status (reusable for typing indicator reset) ───
function updateHeaderStatus(conv) {
    const hdrStatus = document.getElementById('chat-header-status');
    if (conv.type === 'direct') {
        if (conv.is_online) {
            hdrStatus.innerHTML = '<span class="online-status-dot online"></span>Online';
            hdrStatus.className = 'chat-header-status';
        } else if (conv.last_seen_at) {
            hdrStatus.textContent = 'Last seen: ' + new Date(conv.last_seen_at).toLocaleString();
            hdrStatus.className = 'chat-header-status offline';
        } else {
            hdrStatus.textContent = 'Offline';
            hdrStatus.className = 'chat-header-status offline';
        }
    } else {
        hdrStatus.textContent = '';
        hdrStatus.className = 'chat-header-status';
    }
}

// ─── Chat Area ───
async function selectConversation(id) {
    if (appState.activeConvId === id) return;
    
    appState.activeConvId = id;
    appState.messages = [];
    appState.isScrolledUp = false;
    cancelReply();
    cancelAttachment();
    
    renderConversations(); // Update active highlight

    const conv = appState.conversations.find(c => c.id == id);
    if (!conv) return;
    conv.unread_count = 0; // Prevent SSE heartbeat from ghosting the badge back

    // Update Header
    const hdrName = document.getElementById('chat-header-name');
    const hdrIcon = document.getElementById('chat-header-icon');
    const hdrStatus = document.getElementById('chat-header-status');
    const hdrActions = document.getElementById('chat-header-actions');
    const btnAdd = document.getElementById('btn-add-user');
    const btnLeave = document.getElementById('btn-leave-conv');
    const btnDel = document.getElementById('btn-del-conv');
    const cntBtn = document.getElementById('member-count-btn');
    
    hdrName.textContent = conv.name;
    hdrStatus.innerHTML = '';
    
    if (conv.type === 'community') hdrIcon.textContent = '🌍';
    else if (conv.type === 'group') hdrIcon.textContent = '👥';
    else hdrIcon.textContent = '👤';

    // Header actions
    hdrActions.style.display = 'flex';
    
    // Add user button (community/group admin only)
    if (conv.type !== 'direct' && conv.my_role === 'admin') {
        btnAdd.style.display = 'inline-block';
    } else {
        btnAdd.style.display = 'none';
    }

    // Leave/Delete buttons
    if (conv.type === 'direct') {
        btnLeave.style.display = 'none';
        btnDel.style.display = 'none';
    } else {
        btnLeave.style.display = 'inline-block';
        if (appState.permissions.can_manage_communities || conv.my_role === 'admin') {
            btnDel.style.display = 'inline-block';
        } else {
            btnDel.style.display = 'none';
        }
    }

    // Status / Members
    updateHeaderStatus(conv);
    if (conv.type !== 'direct') {
        cntBtn.style.display = 'inline-flex';
        updateMemberCount(id);
    } else {
        cntBtn.style.display = 'none';
    }

    // Check participation status
    const inputArea = document.getElementById('chat-input-area');
    const msgContainer = document.getElementById('messages-container');
    
    if (conv.my_status === 'pending') {
        inputArea.style.display = 'none';
        msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:3rem;margin-bottom:10px;">⏳</div>
                <h3>Approval Pending</h3>
                <p>You have requested to join this community. You can view and send messages once an admin approves your request.</p>
            </div>
        `;
    } else if (conv.my_status !== 'approved' && conv.type === 'community') {
        inputArea.style.display = 'none';
        msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:3rem;margin-bottom:10px;">👋</div>
                <h3>Join Community</h3>
                <p>You need to join this community to participate.</p>
                <button class="chat-btn-join" onclick="joinCommunity(${conv.id})">Request to Join</button>
            </div>
        `;
    } else {
        inputArea.style.display = 'flex';
        msgContainer.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);">Loading messages...</div>';
        
        // Initial Fetch
        await loadMessages(id);
        
        // Mark read
        fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'mark_read', conversation_id: id })
        });

        // Auto-focus the input
        document.getElementById('message-input').focus();
    }

    // Optimistically clear unread badge from sidebar
    const sidebarItem = document.querySelector(`.room-item[data-conv-id="${id}"]`);
    if (sidebarItem) {
        const badge = sidebarItem.querySelector('.room-unread');
        if (badge) badge.remove();
    }

    // Reset input state to prevent draft bleed between conversations
    const input = document.getElementById('message-input');
    if (input) { input.value = ''; input.style.height = '42px'; }
    updateSendButtonState();

    // Restart SSE to focus on this conversation
    initSSE();
}

async function loadMessages(convId) {
    try {
        const res = await fetch(`../api/chat/get_messages.php?conversation_id=${convId}`);
        const data = await res.json();
        
        if (appState.activeConvId !== convId) return; // Race condition check

        if (data.success) {
            appState.messages = data.messages;
            renderMessages(true);
        }
    } catch (e) {
        console.error("Failed to load messages:", e);
    }
}

function renderMessages(forceScroll = false) {
    const container = document.getElementById('messages-container');
    const wasScrolledToBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
    
    if (appState.messages.length === 0) {
        container.innerHTML = '<div class="disabled-state">No messages yet. Say hello! 👋</div>';
        return;
    }

    // WhatsApp-style sender name colors (no avatar circles in chat — WhatsApp Web style)
    const senderColors = ['#25D366', '#7C3AED', '#E67E22', '#2563EB', '#E91E63', '#00ACC1'];

    let html = '';
    let lastDate = '';
    let prevMsg = null;

    appState.messages.forEach((msg, idx) => {
        const isOwn = msg.sender_id == appState.user.id;
        const msgDate = new Date(msg.created_at).toLocaleDateString();
        const time = new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        
        // Date separator
        if (msgDate !== lastDate) {
            html += `<div class="msg-date-divider"><span>${formatDividerDate(msg.created_at)}</span></div>`;
            lastDate = msgDate;
            prevMsg = null; // reset clustering on date change
        }

        // ── Message Clustering Logic ──
        // Cluster if same sender, within 2 minutes, and neither is deleted
        const isClustered = prevMsg
            && prevMsg.sender_id === msg.sender_id
            && !msg.deleted_for_all && !prevMsg.deleted_for_all
            && (new Date(msg.created_at) - new Date(prevMsg.created_at)) < 120000;

        // Peek ahead to see if the NEXT message continues the cluster
        const nextMsg = appState.messages[idx + 1];
        const nextIsSameSender = nextMsg
            && nextMsg.sender_id === msg.sender_id
            && !nextMsg.deleted_for_all && !msg.deleted_for_all
            && (new Date(nextMsg.created_at) - new Date(msg.created_at)) < 120000
            && new Date(nextMsg.created_at).toLocaleDateString() === msgDate;

        // Determine cluster position class
        let clusterClass = '';
        if (isClustered && nextIsSameSender) clusterClass = 'cluster-mid';
        else if (isClustered && !nextIsSameSender) clusterClass = 'cluster-end';
        else if (!isClustered && nextIsSameSender) clusterClass = 'cluster-start';

        const showSenderInfo = !isOwn && !isClustered;

        const badgeHtml = (showSenderInfo && (msg.sender_role === 'admin' || msg.sender_role === 'moderator')) 
            ? `<span class="msg-admin-badge ${msg.sender_role}">${msg.sender_role}</span>` : '';

        // Media
        let mediaHtml = '';
        if (msg.media_url && !msg.deleted_for_all) {
            if (msg.media_type === 'image') {
                mediaHtml = `<div class="msg-media"><img src="../${msg.media_url}" onload="handleImageLoad()" onclick="openLightbox('../${msg.media_url}')" alt="Attachment"></div>`;
            } else if (msg.media_type === 'video') {
                mediaHtml = `<div class="msg-media"><video src="../${msg.media_url}" controls></video></div>`;
            } else if (msg.media_type === 'audio') {
                mediaHtml = `<div class="msg-media"><audio src="../${msg.media_url}" controls></audio></div>`;
            } else {
                mediaHtml = `
                    <div class="msg-media-doc" onclick="window.open('../${msg.media_url}', '_blank')">
                        <div class="doc-icon">📄</div>
                        <div class="doc-name">${msg.media_name || 'Document'}</div>
                    </div>`;
            }
        }

        // Reply context
        let replyHtml = '';
        if (msg.reply_to_id && msg.reply_content && !msg.deleted_for_all) {
            replyHtml = `
                <div class="msg-reply-quote" onclick="scrollToMessage(${msg.reply_to_id})">
                    <div class="reply-sender">${msg.reply_sender_id == appState.user.id ? 'You' : escapeHTML(msg.reply_sender_name)}</div>
                    <div class="reply-text">${escapeHTML(msg.reply_content)}</div>
                </div>`;
        }

        // Forward label
        let forwardHtml = '';
        if (msg.forwarded_from && !msg.deleted_for_all) {
            forwardHtml = `<div class="msg-forwarded">↪ Forwarded</div>`;
        }

        // Status Ticks (for own messages)
        let ticksHtml = '';
        if (isOwn && !msg.deleted_for_all) {
            const tickIcon = msg.status === 'read' ? '✓✓' : (msg.status === 'delivered' ? '✓✓' : '✓');
            ticksHtml = `<span class="msg-ticks ${msg.status}">${tickIcon}</span>`;
        }

        const contentHtml = msg.deleted_for_all ? `<i style="color:var(--muted)">🚫 This message was deleted</i>` : escapeHTML(msg.content);

        html += `
            <div class="msg-row ${isOwn ? 'own' : ''} ${clusterClass} ${isClustered ? 'clustered' : ''}" id="msg-${msg.id}">
                <div class="msg-group ${isOwn ? 'own' : ''} ${clusterClass}">
                    ${showSenderInfo ? `<div class="msg-sender" style="color: ${senderColors[msg.sender_id % senderColors.length]}">${msg.first_name} ${msg.last_name} ${badgeHtml}</div>` : ''}
                    <div class="msg-bubble">
                        ${!msg.deleted_for_all ? `<div class="msg-menu-btn" onclick="showContextMenu(event, ${msg.id}, ${isOwn}, ${msg.deleted_for_all})">⌄</div>` : ''}
                        ${forwardHtml}
                        ${replyHtml}
                        ${mediaHtml}
                        <div class="msg-content">${contentHtml}</div>
                        <div class="msg-time">${time} ${ticksHtml}</div>
                    </div>
                </div>
            </div>
        `;

        prevMsg = msg;
    });

    container.innerHTML = html;

    // Audio mutex handled by delegated listener in DOMContentLoaded

    // Scroll handling
    if (forceScroll || (!appState.isScrolledUp && wasScrolledToBottom)) {
        container.scrollTop = container.scrollHeight;
    }
}

function handleScroll() {
    const container = document.getElementById('messages-container');
    const isAtBottom = container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
    appState.isScrolledUp = !isAtBottom;

    // Toggle scroll-to-bottom FAB
    const fab = document.getElementById('btn-scroll-bottom');
    if (fab) {
        if (appState.isScrolledUp) {
            fab.classList.add('visible');
        } else {
            fab.classList.remove('visible');
        }
    }
}

function scrollToBottom() {
    const container = document.getElementById('messages-container');
    setTimeout(() => {
        container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
    }, 50);
}

function handleImageLoad() {
    if (!appState.isScrolledUp) scrollToBottom();
}

// ─── Input & Sending ───
function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 25 * 1024 * 1024) {
        alert("File too large. Maximum size is 25MB.");
        event.target.value = '';
        return;
    }

    appState.attachedFile = file;
    const previewBar = document.getElementById('file-preview-bar');
    const previewName = document.getElementById('file-preview-name');
    const sizeLabel = `${file.name} (${(file.size/1024/1024).toFixed(1)}MB)`;

    // If it's an image, show a thumbnail preview
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
            previewName.innerHTML = `<img src="${e.target.result}" alt="preview" style="width:36px;height:36px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:8px;">${sizeLabel}`;
        };
        reader.readAsDataURL(file);
    } else {
        previewName.textContent = sizeLabel;
    }

    previewBar.style.display = 'flex';
    updateSendButtonState();
    document.getElementById('message-input')?.focus();
}

function cancelAttachment() {
    appState.attachedFile = null;
    document.getElementById('file-upload').value = '';
    document.getElementById('file-preview-bar').style.display = 'none';
    updateSendButtonState();
    document.getElementById('message-input')?.focus();
}

async function sendMessage() {
    const input = document.getElementById('message-input');
    const content = input.value.trim();
    
    // Guard: bail before any UI side-effects
    if (!content && !appState.attachedFile) return;
    if (!appState.activeConvId) return;

    // Visual feedback: briefly pulse the send button
    const sendBtn = document.getElementById('btn-send');
    if (sendBtn) {
        sendBtn.classList.add('sending');
        setTimeout(() => sendBtn.classList.remove('sending'), 150);
    }

    // Disable send button to prevent double-send
    if (sendBtn) sendBtn.disabled = true;

    const formData = new FormData();
    formData.append('conversation_id', appState.activeConvId);
    if (content) formData.append('content', content);
    if (appState.replyToId) formData.append('reply_to_id', appState.replyToId);
    if (appState.forwardMessageId) formData.append('forwarded_from', appState.forwardMessageId);
    if (appState.attachedFile) formData.append('media', appState.attachedFile);

    // Optimistic UI update
    input.value = '';
    input.style.height = '42px'; // Snap back to single-line height
    if (sendBtn) sendBtn.textContent = '🎤'; // Reset to mic
    cancelReply();
    cancelAttachment();
    clearTimeout(appState.typingTimeout);
    const hdrStatus = document.getElementById('chat-header-status');
    if (hdrStatus && hdrStatus.classList.contains('typing')) {
        const conv = appState.conversations.find(c => c.id == appState.activeConvId);
        if (conv) updateHeaderStatus(conv);
    }
    fetch('../api/chat/manage_conversations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'typing_stop', conversation_id: appState.activeConvId })
    });

    try {
        const res = await fetch('../api/chat/send_message.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (!data.success) {
            alert("Error sending message: " + data.message);
        }
    } catch (e) {
        console.error("Failed to send message", e);
    } finally {
        if (sendBtn) sendBtn.disabled = false;
        document.getElementById('message-input').focus();
    }
}

function handleTyping() {
    if (!appState.activeConvId) return;

    // Toggle send/mic icon (accounts for attachments too)
    updateSendButtonState();
    const input = document.getElementById('message-input');

    // Auto-resize textarea (strict reset when empty to prevent snap-back glitch)
    if (!input.value) {
        input.style.height = '42px';
    } else {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    fetch('../api/chat/manage_conversations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'typing_start', conversation_id: appState.activeConvId })
    });

    clearTimeout(appState.typingTimeout);
    appState.typingTimeout = setTimeout(() => {
        fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'typing_stop', conversation_id: appState.activeConvId })
        });
    }, 2000);
}

// ─── Message Context Menu ───
let contextMenuNode = null;

function showContextMenu(e, msgId, isOwn, isDeleted) {
    e.preventDefault();
    e.stopPropagation();
    closeContextMenu();

    if (isDeleted) return;

    const menu = document.createElement('div');
    menu.className = 'msg-context-menu';
    menu.style.left = `${e.pageX}px`;
    menu.style.top = `${e.pageY}px`;

    menu.innerHTML = `
        <div class="msg-context-item" onclick="initReply(${msgId})">↩ Reply</div>
        <div class="msg-context-item" onclick="openForwardModal(${msgId})">↪ Forward</div>
        <div class="msg-context-item danger" onclick="deleteMessage(${msgId}, 'for_me')">🗑 Delete for me</div>
        ${isOwn ? `<div class="msg-context-item danger" onclick="deleteMessage(${msgId}, 'for_all')">🚫 Delete for everyone</div>` : ''}
    `;

    document.body.appendChild(menu);
    contextMenuNode = menu;

    // Adjust position if offscreen
    const rect = menu.getBoundingClientRect();
    if (rect.bottom > window.innerHeight) menu.style.top = `${e.pageY - rect.height}px`;
    if (rect.right > window.innerWidth) menu.style.left = `${e.pageX - rect.width}px`;
}

function closeContextMenu() {
    if (contextMenuNode) {
        contextMenuNode.remove();
        contextMenuNode = null;
    }
}

function initReply(msgId) {
    const msg = appState.messages.find(m => m.id === msgId);
    if (!msg) return;

    appState.replyToId = msgId;
    document.getElementById('reply-bar-sender').textContent = msg.sender_id == appState.user.id ? 'You' : `${msg.first_name} ${msg.last_name}`;
    document.getElementById('reply-bar-text').textContent = msg.content || (msg.media_url ? 'Attachment' : '');
    document.getElementById('reply-bar').style.display = 'flex';
    document.getElementById('message-input').focus();
}

function cancelReply() {
    appState.replyToId = null;
    document.getElementById('reply-bar').style.display = 'none';
}

async function deleteMessage(msgId, type) {
    if (!confirm(type === 'for_all' ? "Delete message for everyone?" : "Delete message for yourself?")) return;

    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'delete_message', message_id: msgId, delete_type: type })
        });
        const data = await res.json();
        if (data.success) {
            // Optimistic update
            if (type === 'for_all') {
                const msg = appState.messages.find(m => m.id === msgId);
                if (msg) {
                    msg.deleted_for_all = 1;
                    msg.content = null;
                    msg.media_url = null;
                }
            } else {
                appState.messages = appState.messages.filter(m => m.id !== msgId);
            }
            renderMessages();
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error("Delete failed", e);
    }
}

// ─── Forward Modal ───
function openForwardModal(msgId) {
    appState.forwardMessageId = msgId;
    document.getElementById('forward-modal').style.display = 'flex';
    document.getElementById('forward-search').value = '';
    filterForwardList();
}

function closeForwardModal() {
    document.getElementById('forward-modal').style.display = 'none';
    appState.forwardMessageId = null;
}

function filterForwardList() {
    const term = document.getElementById('forward-search').value.toLowerCase();
    const list = document.getElementById('forward-list');
    let html = '';

    appState.conversations.forEach(c => {
        if (c.my_status !== 'approved') return;
        if (term && !c.name.toLowerCase().includes(term)) return;

        let icon = c.type === 'community' ? '🌍' : (c.type === 'group' ? '👥' : '👤');
        html += `
            <div class="forward-conv-item" onclick="executeForward(${c.id})">
                <div class="forward-conv-icon">${icon}</div>
                <div class="forward-conv-name">${c.name}</div>
            </div>
        `;
    });

    list.innerHTML = html || '<div style="padding:20px;text-align:center;color:#64748b;">No matching conversations.</div>';
}

async function executeForward(targetConvId) {
    if (!appState.forwardMessageId) return;

    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 
                action: 'forward_message', 
                message_id: appState.forwardMessageId,
                target_conversation_id: targetConvId
            })
        });
        const data = await res.json();
        if (data.success) {
            closeForwardModal();
            selectConversation(targetConvId); // Jump to forwarded chat
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error("Forward failed", e);
    }
}

// ─── Participants & Members ───
async function updateMemberCount(convId) {
    try {
        const res = await fetch(`../api/chat/get_participants.php?conversation_id=${convId}`);
        const data = await res.json();
        if (data.success && appState.activeConvId === convId) {
            document.getElementById('member-count-text').textContent = `${data.count} members (${data.online_count} online)`;
        }
    } catch (e) {
        console.error(e);
    }
}

async function openMembersModal() {
    if (!appState.activeConvId) return;
    
    try {
        const res = await fetch(`../api/chat/get_participants.php?conversation_id=${appState.activeConvId}`);
        const data = await res.json();
        if (!data.success) return;

        // Determine if I am admin in this conversation
        const conv = appState.conversations.find(c => c.id == appState.activeConvId);
        const iAmAdmin = conv && conv.my_role === 'admin';
        const isGroupOrCommunity = conv && conv.type !== 'direct';

        document.getElementById('members-modal-count').textContent = data.count;
        const list = document.getElementById('members-modal-list');
        const avatarColors = ['#25D366', '#7C3AED', '#E67E22', '#2563EB', '#E91E63', '#00ACC1'];
        let html = '';

        data.participants.forEach(p => {
            const isMe = p.user_id == appState.user.id;
            const badge = p.system_role === 'admin' ? '<span class="member-badge-system admin">Admin</span>' : 
                         (p.system_role === 'moderator' ? '<span class="member-badge-system moderator">Mod</span>' : '');
            
            const chatBadge = p.chat_role === 'admin' ? '<span class="member-badge-chat">Chat Admin</span>' : '';
            const statusIndicator = p.is_online ? '<span class="online-status-dot online"></span>' : '';
            const avColor = avatarColors[p.user_id % avatarColors.length];

            // Show remove button if I am admin, it's a group/community, and it's not myself
            const removeBtn = (iAmAdmin && isGroupOrCommunity && !isMe)
                ? `<button class="member-remove-btn" onclick="removeUser(${appState.activeConvId}, ${p.user_id})" title="Remove user">✖</button>`
                : '';

            html += `
                <div class="members-modal-item">
                    <div class="members-modal-av" style="background: ${avColor}">${escapeHTML(p.first_name).charAt(0)}</div>
                    <div class="members-modal-info">
                        <div class="members-modal-name">${statusIndicator}${escapeHTML(p.first_name)} ${escapeHTML(p.last_name)} ${isMe ? '(You)' : ''}</div>
                        <div class="members-modal-username">@${escapeHTML(p.username)}</div>
                    </div>
                    <div class="members-modal-badges">${badge}${chatBadge}${removeBtn}</div>
                </div>
            `;
        });

        list.innerHTML = html;
        document.getElementById('members-modal').style.display = 'flex';
    } catch (e) {
        console.error(e);
    }
}

function closeMembersModal() {
    document.getElementById('members-modal').style.display = 'none';
}

async function removeUser(convId, targetUserId) {
    if (!confirm('Remove this user from the conversation?')) return;

    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'remove_user', conversation_id: convId, target_user_id: targetUserId })
        });
        const data = await res.json();
        if (data.success) {
            alert('User removed successfully.');
            updateMemberCount(convId);
            openMembersModal(); // Re-render the modal with updated list
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error('Remove user failed', e);
        alert('Failed to remove user.');
    }
}

// ─── Actions (Create, Add, Leave, Delete) ───
let currentAction = null;

function openActionModal(actionType) {
    currentAction = actionType;
    const title = document.getElementById('modal-title');
    const input = document.getElementById('modal-input');
    
    if (actionType === 'direct') {
        title.textContent = 'New Direct Message';
        input.placeholder = 'Enter username or email...';
    } else if (actionType === 'group') {
        title.textContent = 'Create New Group';
        input.placeholder = 'Enter group name...';
    } else if (actionType === 'community') {
        title.textContent = 'Create New Community';
        input.placeholder = 'Enter community name...';
    } else if (actionType === 'add_user') {
        title.textContent = 'Add User to Chat';
        input.placeholder = 'Enter username or email...';
    }
    
    input.value = '';
    document.getElementById('action-modal').style.display = 'flex';
    input.focus();
}

function closeModal() {
    document.getElementById('action-modal').style.display = 'none';
    currentAction = null;
}

document.getElementById('modal-submit').addEventListener('click', async () => {
    const val = document.getElementById('modal-input').value.trim();
    if (!val) return;
    
    const formData = new URLSearchParams();
    
    if (currentAction === 'direct') {
        formData.append('action', 'create_direct');
        formData.append('target_username', val);
    } else if (currentAction === 'group') {
        formData.append('action', 'create_group');
        formData.append('name', val);
    } else if (currentAction === 'community') {
        formData.append('action', 'create_community');
        formData.append('name', val);
    } else if (currentAction === 'add_user') {
        formData.append('action', 'add_user');
        formData.append('conversation_id', appState.activeConvId);
        formData.append('target_username', val);
    }
    
    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            closeModal();
            fetchConversations();
            if (data.conversation_id) selectConversation(data.conversation_id);
            if (currentAction === 'add_user') updateMemberCount(appState.activeConvId);
        } else {
            alert(data.message);
        }
    } catch (e) {
        console.error(e);
        alert("Action failed.");
    }
});

async function leaveConversation() {
    if (!appState.activeConvId) return;
    if (!confirm("Are you sure you want to leave this conversation?")) return;

    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'leave_conversation', conversation_id: appState.activeConvId })
        });
        const data = await res.json();
        if (data.success) {
            appState.activeConvId = null;
            document.getElementById('chat-input-area').style.display = 'none';
            document.getElementById('messages-container').innerHTML = '<div class="disabled-state">Select a conversation.</div>';
            document.getElementById('chat-header-actions').style.display = 'none';
            document.getElementById('chat-header-name').textContent = 'Select a conversation';
            document.getElementById('chat-header-status').textContent = '';
            document.getElementById('member-count-btn').style.display = 'none';
            fetchConversations();
        } else {
            alert(data.message);
        }
    } catch(e) { console.error(e); }
}

async function deleteConversation() {
    if (!appState.activeConvId) return;
    if (!confirm("WARNING: This will permanently delete the entire conversation for EVERYONE. Continue?")) return;

    try {
        const res = await fetch('../api/chat/manage_conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'delete_conversation', conversation_id: appState.activeConvId })
        });
        const data = await res.json();
        if (data.success) {
            appState.activeConvId = null;
            document.getElementById('chat-input-area').style.display = 'none';
            document.getElementById('messages-container').innerHTML = '<div class="disabled-state">Select a conversation.</div>';
            document.getElementById('chat-header-actions').style.display = 'none';
            document.getElementById('chat-header-name').textContent = 'Select a conversation';
            document.getElementById('chat-header-status').textContent = '';
            document.getElementById('member-count-btn').style.display = 'none';
            fetchConversations();
        } else {
            alert(data.message);
        }
    } catch(e) { console.error(e); }
}

async function joinCommunity(id) {
    try {
        const res = await fetch('../api/chat/community_requests.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'request_join', conversation_id: id })
        });
        const data = await res.json();
        if (data.success) {
            alert("Join request sent to community admins.");
            fetchConversations();
            selectConversation(id);
        } else {
            alert(data.message);
        }
    } catch (e) { console.error(e); }
}

// ─── Lightbox ───
function openLightbox(src) {
    const overlay = document.createElement('div');
    overlay.className = 'lightbox-overlay';
    overlay.onclick = () => overlay.remove();
    overlay.innerHTML = `<img src="${src}" alt="Full screen preview">`;
    document.body.appendChild(overlay);
}

// ─── Community Requests (Admin Only) ───
async function fetchRequests() {
    try {
        const res = await fetch('../api/chat/community_requests.php?action=get_requests');
        const data = await res.json();
        if (data.success) {
            const list = document.getElementById('requests-list');
            if (data.requests.length === 0) {
                list.innerHTML = '<div style="color:var(--muted);font-size:0.85rem;">No pending requests.</div>';
                return;
            }
            let html = '';
            data.requests.forEach(req => {
                html += `
                    <div class="request-item" id="req-${req.conversation_id}-${req.user_id}">
                        <div style="font-size:0.85rem;font-weight:600;">${req.first_name} ${req.last_name}</div>
                        <div style="font-size:0.75rem;color:var(--muted);margin-bottom:6px;">wants to join ${req.community_name}</div>
                        <div class="request-actions">
                            <button class="btn-approve" onclick="handleRequest(${req.conversation_id}, ${req.user_id}, 'approve')">✓</button>
                            <button class="btn-reject" onclick="handleRequest(${req.conversation_id}, ${req.user_id}, 'reject')">✕</button>
                        </div>
                    </div>
                `;
            });
            list.innerHTML = html;
        }
    } catch (e) { console.error(e); }
}

async function handleRequest(convId, userId, action) {
    try {
        const res = await fetch('../api/chat/community_requests.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'handle_request', conversation_id: convId, target_user_id: userId, status: action === 'approve' ? 'approved' : 'rejected' })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById(`req-${convId}-${userId}`).remove();
        } else {
            alert(data.message);
        }
    } catch (e) { console.error(e); }
}

// Utility: Scroll to message
function scrollToMessage(id) {
    const el = document.getElementById('msg-' + id);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.style.backgroundColor = 'rgba(46,117,182,0.2)';
        setTimeout(() => el.style.backgroundColor = 'transparent', 1500);
    }
}
