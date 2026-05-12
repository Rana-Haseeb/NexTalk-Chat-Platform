// ==============================================
// NexTalk — Dashboard Logic (Phase 3)
// Implements SSE, WhatsApp Features, DOM Diffing
// ==============================================

// ─── Helpers ───
function escapeHTML(str) {
  if (!str) return "";
  const div = document.createElement("div");
  div.textContent = str;
  return div.innerHTML;
}

function updateSendButtonState() {
  const input = document.getElementById("message-input");
  const sendBtn = document.getElementById("btn-send");
  if (sendBtn) {
    sendBtn.textContent =
      input.value.trim() || appState.attachedFile ? "➤" : "🎤";
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
  isScrolledUp: false,
  blockState: { i_blocked: false, they_blocked: false, any_block: false },
  pollCache: {},
  pollFetchInFlight: {},
  // ─── AI ───
  smartRepliesInFlight: false,
  smartRepliesAbort: null,        // AbortController for in-flight smart-replies fetch
  smartRepliesForConvId: null,    // which conv the current chips belong to
  translationCache: {},           // { [msgId]: { translated, original, target_lang, showingTranslation } }
  translationInFlight: {},        // { [msgId]: true }
};

// ─── Initialization ───
document.addEventListener("DOMContentLoaded", async () => {
  try {
    const res = await fetch("../api/check_auth.php");
    const data = await res.json();

    if (!data.authenticated) {
      window.location.replace("auth.html");
      return;
    }

    appState.user = data.user;
    appState.permissions = data.user.permissions || {};

    // UI Setup
    document.getElementById("user-display-name").textContent =
      `${data.user.first_name} ${data.user.last_name}`;
    document.getElementById("user-avatar").textContent =
      data.user.first_name.charAt(0);
    document.getElementById("user-role-badge").textContent = data.user.role;
    document.getElementById("user-role-badge").className =
      `user-role-badge ${data.user.role}`;

    document.getElementById("member-self-name").textContent =
      `${data.user.first_name} ${data.user.last_name}`;
    document.getElementById("member-self-avatar").textContent =
      data.user.first_name.charAt(0);

    if (appState.permissions.can_manage_communities) {
      document.getElementById("btn-new-community").style.display = "block";
      document.getElementById("community-requests-section").style.display =
        "block";
      setupRequestsAutoRefresh();
    }

    document.getElementById("auth-guard").style.display = "none";
    document.getElementById("app-shell").style.display = "flex";

    // Initial Data Load
    await fetchConversations();

    // Start SSE connection
    initSSE();

    // Event listeners
    document.addEventListener("click", closeContextMenu);

    // Shift+Enter = newline, Enter = send
    document
      .getElementById("message-input")
      .addEventListener("keydown", (e) => {
        if (e.key === "Enter" && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });

    // Global Escape key handler
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        // Close lightbox
        const lb = document.querySelector(".lightbox-overlay");
        if (lb) {
          lb.remove();
          return;
        }
        // Close context menu
        closeContextMenu();
        // Close modals
        closeForwardModal();
        closeMembersModal();
        closeModal();
        closePollModal();
        // Cancel inline UI
        cancelReply();
        cancelAttachment();
        // Restore focus for accessibility
        document.getElementById("message-input")?.focus();
      }
    });

    // Audio mutex via event delegation (prevents listener leak on re-render)
    document.getElementById("messages-container").addEventListener(
      "play",
      (e) => {
        if (e.target.tagName === "AUDIO") {
          document.querySelectorAll("audio").forEach((audio) => {
            if (audio !== e.target) audio.pause();
          });
        }
      },
      true,
    );

    // Window focus/blur listeners for conversation refresh
    window.addEventListener("focus", () => {
      // Refresh conversations when user returns to the tab
      fetchConversations();
    });

    // Background periodic refresh (every 45 seconds)
    setInterval(fetchConversations, 45000);
  } catch (e) {
    console.error("Auth check failed:", e);
    // Don't redirect here — the explicit auth check above handles unauthorized users.
    // Redirecting on ANY error causes infinite loops when the issue is unrelated to auth.
    document.getElementById("auth-guard").innerHTML = `
            <div class="guard-content">
                <div class="guard-logo">Nex<span>Talk</span></div>
                <p style="color:#e74c3c;">Connection error. Please refresh the page.</p>
            </div>`;
  }
});

async function handleLogout() {
  await fetch("../api/logout.php");
  window.location.replace("auth.html");
}

// ─── Server-Sent Events (SSE) ───
function initSSE() {
  if (appState.sseSource) appState.sseSource.close();

  const url = new URL("../api/chat/sse.php", window.location.href);
  if (appState.activeConvId) {
    url.searchParams.append("conversation_id", appState.activeConvId);
    const lastMsg = appState.messages[appState.messages.length - 1];
    if (lastMsg) url.searchParams.append("last_message_id", lastMsg.id);
  }

  appState.sseSource = new EventSource(url.href);

  // Heartbeat to keep connection alive
  appState.sseSource.onmessage = (e) => {
    if (e.data === "{}") return; // Keepalive/reconnect signal
    // Re-fetch conversation list to update previews and unread counts
    fetchConversations();
  };

  appState.sseSource.addEventListener("messages", (e) => {
    const newMessages = JSON.parse(e.data);
    if (newMessages.length > 0) {
      // Task 4: Play notification sound for incoming messages from others
      const hasIncoming = newMessages.some(
        (m) => m.sender_id != appState.user.id,
      );
      if (hasIncoming) {
        new Audio(
          "https://assets.mixkit.co/active_storage/sfx/2354/2354-preview.mp3",
        )
          .play()
          .catch((e) => {});
      }

      appState.messages = [...appState.messages, ...newMessages];
      renderMessages();
      // Tell server we read them
      fetch("../api/chat/manage_conversations.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "mark_read",
          conversation_id: appState.activeConvId,
        }),
      });

      // AI Smart Replies: refresh when *they* sent something new
      if (hasIncoming) fetchSmartReplies(false);
    }
  });

  appState.sseSource.addEventListener("status", (e) => {
    const updates = JSON.parse(e.data);
    let changed = false;
    updates.forEach((u) => {
      const msg = appState.messages.find((m) => m.id === u.id);
      if (msg && msg.status !== u.status) {
        msg.status = u.status;
        changed = true;
      }
    });
    if (changed) renderMessages(false); // don't force scroll
  });

  appState.sseSource.addEventListener("typing", (e) => {
    const typers = JSON.parse(e.data);
    const hdrStatus = document.getElementById("chat-header-status");
    if (typers.length > 0) {
      const names = typers.map((t) => t.first_name).join(", ");
      hdrStatus.textContent = `${names} ${typers.length > 1 ? "are" : "is"} typing...`;
      hdrStatus.className = "chat-header-status typing";
    } else {
      // Restore original status from active conversation data
      const conv = appState.conversations.find(
        (c) => c.id == appState.activeConvId,
      );
      if (conv) updateHeaderStatus(conv);
    }
  });

  // Real-time deletion sync
  appState.sseSource.addEventListener("deletions", (e) => {
    const deletedIds = JSON.parse(e.data);
    if (!deletedIds.length) return;
    let changed = false;
    deletedIds.forEach((id) => {
      const msg = appState.messages.find((m) => m.id === id);
      if (msg && !msg.deleted_for_all) {
        msg.deleted_for_all = 1;
        msg.content = null;
        msg.media_url = null;
        changed = true;
      }
    });
    if (changed) renderMessages(false);
  });

  // Real-time poll vote sync
  appState.sseSource.addEventListener("poll_update", (e) => {
    const updatedMessageIds = JSON.parse(e.data);
    updatedMessageIds.forEach((msgId) => {
      // Invalidate cache so the re-fetch gets fresh vote counts
      delete appState.pollCache[msgId];
      fetchPollData(msgId);
    });
  });

  appState.sseSource.addEventListener("reconnect", () => {
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
    const res = await fetch("../api/chat/manage_conversations.php");
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
  if (!dateStr) return "";
  const d = new Date(dateStr);
  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  if (isToday) {
    return d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  }
  return d.toLocaleDateString([], {
    month: "numeric",
    day: "numeric",
    year: "numeric",
  });
}

// Helper: human-friendly date divider labels
function formatDividerDate(dateString) {
  const d = new Date(dateString);
  const now = new Date();
  const yesterday = new Date();
  yesterday.setDate(now.getDate() - 1);

  if (d.toDateString() === now.toDateString()) return "TODAY";
  if (d.toDateString() === yesterday.toDateString()) return "YESTERDAY";
  return d.toLocaleDateString([], {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  });
}

function renderConversations() {
  const coms = [],
    grps = [],
    dirs = [];
  const searchTerm = document
    .getElementById("sidebar-search")
    .value.toLowerCase();
  let totalUnread = 0;

  appState.conversations.forEach((c) => {
    // Filter by search
    if (searchTerm && !c.name.toLowerCase().includes(searchTerm)) return;

    totalUnread += parseInt(c.unread_count) || 0;

    let icon,
      title = c.name;
    if (c.type === "community") {
      icon = "🌍";
      coms.push(c);
    } else if (c.type === "group") {
      icon = "👥";
      grps.push(c);
    } else {
      icon = "👤";
      dirs.push(c);
    }

    const timeStr = formatSidebarTime(c.last_message_time);

    c._html = `
            <div class="room-item ${c.id == appState.activeConvId ? "active" : ""} ${c.my_status === "pending" ? "pending" : ""}" 
                 onclick="selectConversation(${c.id})" data-conv-id="${c.id}">
                <div class="room-icon">${icon}</div>
                <div class="room-info">
                    <div class="room-name">${escapeHTML(title)}</div>
                    <div class="room-preview">${c.last_message_preview ? escapeHTML(c.last_message_preview) : c.my_status === "pending" ? "<i>Pending Approval</i>" : "<i>No messages yet</i>"}</div>
                </div>
                <div class="room-meta">
                    ${timeStr ? `<div class="room-time">${timeStr}</div>` : ""}
                    ${c.unread_count > 0 ? `<div class="room-unread">${c.unread_count}</div>` : ""}
                </div>
            </div>
        `;
  });

  // DOM Diffing - Only update if changed to prevent scroll jumping
  updateListHTML("list-communities", coms, "hdr-communities");
  updateListHTML("list-groups", grps, "hdr-groups");
  updateListHTML("list-directs", dirs, "hdr-directs");

  // Dynamic browser tab notification
  document.title =
    totalUnread > 0 ? `(${totalUnread}) NexTalk` : "NexTalk — Dashboard";
}

function updateListHTML(containerId, items, headerId) {
  const container = document.getElementById(containerId);
  const header = document.getElementById(headerId);

  if (items.length === 0) {
    container.innerHTML = "";
    header.style.display = "none";
    return;
  }

  header.style.display = "block";
  const newHTML = items.map((c) => c._html).join("");
  if (container.innerHTML !== newHTML) {
    container.innerHTML = newHTML;
  }
}

function filterConversations() {
  renderConversations();
}

// ─── Header Status (reusable for typing indicator reset) ───
function updateHeaderStatus(conv) {
  const hdrStatus = document.getElementById("chat-header-status");
  if (conv.type === "direct") {
    if (conv.is_online) {
      hdrStatus.innerHTML =
        '<span class="online-status-dot online"></span>Online';
      hdrStatus.className = "chat-header-status";
    } else if (conv.last_seen_at) {
      hdrStatus.textContent =
        "Last seen: " + new Date(conv.last_seen_at).toLocaleString();
      hdrStatus.className = "chat-header-status offline";
    } else {
      hdrStatus.textContent = "Offline";
      hdrStatus.className = "chat-header-status offline";
    }
  } else {
    hdrStatus.textContent = "";
    hdrStatus.className = "chat-header-status";
  }
}

// ─── Chat Area ───
async function selectConversation(id) {
  if (appState.activeConvId === id) return;

  if (appState.activeConvId) {
    clearTimeout(appState.typingTimeout);
    fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "typing_stop",
        conversation_id: appState.activeConvId,
      }),
    });
  }

  appState.activeConvId = id;
  appState.messages = [];
  appState.pollCache = {};
  appState.pollFetchInFlight = {};
  appState.translationCache = {};
  appState.translationInFlight = {};
  appState.isScrolledUp = false;
  cancelReply();
  cancelAttachment();
  clearSmartReplies();
  appState.forwardMessageId = null;

  renderConversations(); // Update active highlight

  const conv = appState.conversations.find((c) => c.id == id);
  if (!conv) return;
  conv.unread_count = 0; // Prevent SSE heartbeat from ghosting the badge back

  // Update Header
  const hdrName = document.getElementById("chat-header-name");
  const hdrIcon = document.getElementById("chat-header-icon");
  const hdrStatus = document.getElementById("chat-header-status");
  const hdrActions = document.getElementById("chat-header-actions");
  const btnAdd = document.getElementById("btn-add-user");
  const btnLeave = document.getElementById("btn-leave-conv");
  const btnDel = document.getElementById("btn-del-conv");
  const cntBtn = document.getElementById("member-count-btn");

  hdrName.textContent = conv.name;
  hdrStatus.innerHTML = "";

  if (conv.type === "community") hdrIcon.textContent = "🌍";
  else if (conv.type === "group") hdrIcon.textContent = "👥";
  else hdrIcon.textContent = "👤";

  // Header actions
  hdrActions.style.display = "flex";

  // Add user button (community/group admin only)
  if (conv.type !== "direct" && conv.my_role === "admin") {
    btnAdd.style.display = "inline-block";
  } else {
    btnAdd.style.display = "none";
  }

  // Leave/Delete buttons
  if (conv.type === "direct") {
    btnLeave.style.display = "none";
    btnDel.style.display = "none";
  } else {
    btnLeave.style.display = "inline-block";
    if (
      appState.permissions.can_manage_communities ||
      conv.my_role === "admin"
    ) {
      btnDel.style.display = "inline-block";
    } else {
      btnDel.style.display = "none";
    }
  }

  // Status / Members
  updateHeaderStatus(conv);
  if (conv.type !== "direct") {
    cntBtn.style.display = "inline-flex";
    updateMemberCount(id);
  } else {
    cntBtn.style.display = "none";
  }

  // ─── Block System: show/hide block button for DMs ───
  const blockBtn = document.getElementById("btn-block-user");
  const blockedOverlay = document.getElementById("blocked-overlay");
  if (blockedOverlay) blockedOverlay.style.display = "none";
  appState.blockState = { i_blocked: false, they_blocked: false, any_block: false };

  if (blockBtn) {
    if (conv.type === "direct") {
      // Always show in DMs; toggleBlockUser handles missing other_user_id defensively
      blockBtn.style.display = "block";
      blockBtn.textContent = "🚫 Block User";
      blockBtn.className = "block-btn";
      if (conv.other_user_id) {
        checkBlockStatus(conv.other_user_id, id);
      } else {
        console.warn("DM is missing other_user_id; block status check skipped.");
      }
    } else {
      blockBtn.style.display = "none";
    }
  }

  // ─── Poll button: show only for group/community when approved ───
  const pollBtn = document.getElementById("btn-poll");
  if (pollBtn) {
    const canPoll =
      (conv.type === "group" || conv.type === "community") &&
      conv.my_status === "approved";
    pollBtn.style.display = canPoll ? "flex" : "none";
  }

  // Check participation status
  const inputArea = document.getElementById("chat-input-area");
  const msgContainer = document.getElementById("messages-container");

  if (conv.my_status === "pending") {
    inputArea.style.display = "none";
    msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:3rem;margin-bottom:10px;">⏳</div>
                <h3>Approval Pending</h3>
                <p>You have requested to join this community. You can view and send messages once an admin approves your request.</p>
            </div>
        `;
  } else if (conv.my_status !== "approved" && conv.type === "community") {
    inputArea.style.display = "none";
    msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:3rem;margin-bottom:10px;">👋</div>
                <h3>Join Community</h3>
                <p>You need to join this community to participate.</p>
                <button class="chat-btn-join" onclick="joinCommunity(${conv.id})">Request to Join</button>
            </div>
        `;
  } else {
    inputArea.style.display = "flex";
    msgContainer.innerHTML =
      '<div style="text-align:center;padding:20px;color:var(--muted);">Loading messages...</div>';

    // Initial Fetch
    await loadMessages(id);

    // Mark read
    fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ action: "mark_read", conversation_id: id }),
    });

    // Auto-focus the input
    document.getElementById("message-input").focus();

    // AI Smart Replies — kick off after initial messages render
    fetchSmartReplies(false);
  }

  // Optimistically clear unread badge from sidebar
  const sidebarItem = document.querySelector(
    `.room-item[data-conv-id="${id}"]`,
  );
  if (sidebarItem) {
    const badge = sidebarItem.querySelector(".room-unread");
    if (badge) badge.remove();
  }

  // Reset input state to prevent draft bleed between conversations
  const input = document.getElementById("message-input");
  if (input) {
    input.value = "";
    input.style.height = "42px";
  }
  updateSendButtonState();

  // Restart SSE to focus on this conversation
  initSSE();
}

async function loadMessages(convId) {
  try {
    const res = await fetch(
      `../api/chat/get_messages.php?conversation_id=${convId}`,
    );
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
  const container = document.getElementById("messages-container");
  const wasScrolledToBottom =
    container.scrollHeight - container.scrollTop <= container.clientHeight + 50;

  if (appState.messages.length === 0) {
    container.innerHTML =
      '<div class="disabled-state">No messages yet. Say hello! 👋</div>';
    return;
  }

  // WhatsApp-style sender name colors (no avatar circles in chat — WhatsApp Web style)
  const senderColors = [
    "#25D366",
    "#7C3AED",
    "#E67E22",
    "#2563EB",
    "#E91E63",
    "#00ACC1",
  ];

  let html = "";
  let lastDate = "";
  let prevMsg = null;

  appState.messages.forEach((msg, idx) => {
    const isOwn = msg.sender_id == appState.user.id;
    const msgDate = new Date(msg.created_at).toLocaleDateString();
    const time = new Date(msg.created_at).toLocaleTimeString([], {
      hour: "2-digit",
      minute: "2-digit",
    });

    // Date separator
    if (msgDate !== lastDate) {
      html += `<div class="msg-date-divider"><span>${formatDividerDate(msg.created_at)}</span></div>`;
      lastDate = msgDate;
      prevMsg = null; // reset clustering on date change
    }

    // ── Message Clustering Logic ──
    // Cluster if same sender, within 2 minutes, and neither is deleted
    const isClustered =
      prevMsg &&
      prevMsg.sender_id === msg.sender_id &&
      !msg.deleted_for_all &&
      !prevMsg.deleted_for_all &&
      new Date(msg.created_at) - new Date(prevMsg.created_at) < 120000;

    // Peek ahead to see if the NEXT message continues the cluster
    const nextMsg = appState.messages[idx + 1];
    const nextIsSameSender =
      nextMsg &&
      nextMsg.sender_id === msg.sender_id &&
      !nextMsg.deleted_for_all &&
      !msg.deleted_for_all &&
      new Date(nextMsg.created_at) - new Date(msg.created_at) < 120000 &&
      new Date(nextMsg.created_at).toLocaleDateString() === msgDate;

    // Determine cluster position class
    let clusterClass = "";
    if (isClustered && nextIsSameSender) clusterClass = "cluster-mid";
    else if (isClustered && !nextIsSameSender) clusterClass = "cluster-end";
    else if (!isClustered && nextIsSameSender) clusterClass = "cluster-start";

    const showSenderInfo = !isOwn && !isClustered;

    const badgeHtml =
      showSenderInfo &&
      (msg.sender_role === "admin" || msg.sender_role === "moderator")
        ? `<span class="msg-admin-badge ${msg.sender_role}">${msg.sender_role}</span>`
        : "";

    // Media
    let mediaHtml = "";
    if (msg.media_url && !msg.deleted_for_all) {
      if (msg.media_type === "image") {
        mediaHtml = `<div class="msg-media"><img src="../${msg.media_url}" onload="handleImageLoad()" onclick="openLightbox('../${msg.media_url}')" alt="Attachment"></div>`;
      } else if (msg.media_type === "video") {
        mediaHtml = `<div class="msg-media"><video src="../${msg.media_url}" controls></video></div>`;
      } else if (msg.media_type === "audio") {
        mediaHtml = `<div class="msg-media"><audio src="../${msg.media_url}" controls></audio></div>`;
      } else {
        mediaHtml = `
                    <div class="msg-media-doc" onclick="window.open('../${msg.media_url}', '_blank')">
                        <div class="doc-icon">📄</div>
                        <div class="doc-name">${msg.media_name || "Document"}</div>
                    </div>`;
      }
    }

    // Poll rendering
    let pollHtml = "";
    if (msg.media_type === "poll" && !msg.deleted_for_all) {
      const cachedPoll = appState.pollCache[msg.id];
      if (cachedPoll) {
        // Render directly from cache — no fetch needed
        pollHtml = buildPollBubbleHtml(msg.id, cachedPoll);
      } else {
        pollHtml = `<div class="poll-bubble" id="poll-bubble-${msg.id}">
          <div class="poll-bubble-question">📊 Loading poll...</div>
        </div>`;
      }
    }

    // Reply context
    let replyHtml = "";
    if (msg.reply_to_id && msg.reply_content && !msg.deleted_for_all) {
      replyHtml = `
                <div class="msg-reply-quote" onclick="scrollToMessage(${msg.reply_to_id})">
                    <div class="reply-sender">${msg.reply_sender_id == appState.user.id ? "You" : escapeHTML(msg.reply_sender_name)}</div>
                    <div class="reply-text">${escapeHTML(msg.reply_content)}</div>
                </div>`;
    }

    // Forward label
    let forwardHtml = "";
    if (msg.forwarded_from && !msg.deleted_for_all) {
      forwardHtml = `<div class="msg-forwarded">↪ Forwarded</div>`;
    }

    // Status Ticks (for own messages)
    let ticksHtml = "";
    if (isOwn && !msg.deleted_for_all) {
      const tickIcon =
        msg.status === "read" ? "✓✓" : msg.status === "delivered" ? "✓✓" : "✓";
      ticksHtml = `<span class="msg-ticks ${msg.status}">${tickIcon}</span>`;
    }

    const contentHtml = msg.deleted_for_all
      ? `<i style="color:var(--muted)">🚫 This message was deleted</i>`
      : (msg.media_type === "poll" ? "" : escapeHTML(msg.content));

    html += `
            <div class="msg-row ${isOwn ? "own" : ""} ${clusterClass} ${isClustered ? "clustered" : ""}" id="msg-${msg.id}" data-message-id="${msg.id}">
                <div class="msg-group ${isOwn ? "own" : ""} ${clusterClass}">
                    ${showSenderInfo ? `<div class="msg-sender" style="color: ${senderColors[msg.sender_id % senderColors.length]}">${msg.first_name} ${msg.last_name} ${badgeHtml}</div>` : ""}
                    <div class="msg-bubble">
                        ${!msg.deleted_for_all ? `<div class="msg-menu-btn" onclick="showContextMenu(event, ${msg.id}, ${isOwn}, ${msg.deleted_for_all})">⌄</div>` : ""}
                        ${forwardHtml}
                        ${replyHtml}
                        ${pollHtml}
                        ${mediaHtml}
                        ${contentHtml ? `<div class="msg-content">${contentHtml}</div>` : ""}
                        <div class="msg-time">${time} ${ticksHtml}</div>
                    </div>
                </div>
            </div>
        `;

    prevMsg = msg;
  });

  container.innerHTML = html;

  // Fetch poll data only for uncached polls (prevents N+1 request storm)
  appState.messages.forEach((msg) => {
    if (msg.media_type === "poll" && !msg.deleted_for_all
        && !appState.pollCache[msg.id]
        && !appState.pollFetchInFlight[msg.id]) {
      fetchPollData(msg.id);
    }
  });

  // Audio mutex handled by delegated listener in DOMContentLoaded

  // Scroll handling
  if (forceScroll || (!appState.isScrolledUp && wasScrolledToBottom)) {
    container.scrollTop = container.scrollHeight;
  }
}

function handleScroll() {
  const container = document.getElementById("messages-container");
  const isAtBottom =
    container.scrollHeight - container.scrollTop <= container.clientHeight + 50;
  appState.isScrolledUp = !isAtBottom;

  // Toggle scroll-to-bottom FAB
  const fab = document.getElementById("btn-scroll-bottom");
  if (fab) {
    if (appState.isScrolledUp) {
      fab.classList.add("visible");
    } else {
      fab.classList.remove("visible");
    }
  }
}

function scrollToBottom() {
  const container = document.getElementById("messages-container");
  setTimeout(() => {
    container.scrollTo({ top: container.scrollHeight, behavior: "smooth" });
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
    event.target.value = "";
    return;
  }

  appState.attachedFile = file;
  const previewBar = document.getElementById("file-preview-bar");
  const previewName = document.getElementById("file-preview-name");
  const sizeLabel = `${file.name} (${(file.size / 1024 / 1024).toFixed(1)}MB)`;

  // If it's an image, show a thumbnail preview
  if (file.type.startsWith("image/")) {
    const reader = new FileReader();
    reader.onload = (e) => {
      previewName.innerHTML = `<img src="${e.target.result}" alt="preview" style="width:36px;height:36px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-right:8px;">${sizeLabel}`;
    };
    reader.readAsDataURL(file);
  } else {
    previewName.textContent = sizeLabel;
  }

  previewBar.style.display = "flex";
  updateSendButtonState();
  document.getElementById("message-input")?.focus();
}

function cancelAttachment() {
  appState.attachedFile = null;
  document.getElementById("file-upload").value = "";
  document.getElementById("file-preview-bar").style.display = "none";
  updateSendButtonState();
  document.getElementById("message-input")?.focus();
}

async function sendMessage() {
  const input = document.getElementById("message-input");
  const content = input.value.trim();

  // Guard: bail before any UI side-effects
  if (!content && !appState.attachedFile) return;
  if (!appState.activeConvId) return;

  // Visual feedback: briefly pulse the send button
  const sendBtn = document.getElementById("btn-send");
  if (sendBtn) {
    sendBtn.classList.add("sending");
    setTimeout(() => sendBtn.classList.remove("sending"), 150);
  }

  // Disable send button to prevent double-send
  if (sendBtn) sendBtn.disabled = true;

  const formData = new FormData();
  formData.append("conversation_id", appState.activeConvId);
  if (content) formData.append("content", content);
  if (appState.replyToId) formData.append("reply_to_id", appState.replyToId);
  if (appState.forwardMessageId)
    formData.append("forwarded_from", appState.forwardMessageId);
  if (appState.attachedFile) formData.append("media", appState.attachedFile);

  // Optimistic UI update
  input.value = "";
  input.style.height = "42px"; // Snap back to single-line height
  if (sendBtn) sendBtn.textContent = "🎤"; // Reset to mic
  cancelReply();
  cancelAttachment();
  // Hide stale smart-reply chips after sending; new ones will appear when the
  // other party replies (SSE callback re-triggers fetchSmartReplies).
  clearSmartReplies();
  clearTimeout(appState.typingTimeout);
  const hdrStatus = document.getElementById("chat-header-status");
  if (hdrStatus && hdrStatus.classList.contains("typing")) {
    const conv = appState.conversations.find(
      (c) => c.id == appState.activeConvId,
    );
    if (conv) updateHeaderStatus(conv);
  }
  fetch("../api/chat/manage_conversations.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      action: "typing_stop",
      conversation_id: appState.activeConvId,
    }),
  });

  try {
    const res = await fetch("../api/chat/send_message.php", {
      method: "POST",
      body: formData,
    });
    const data = await res.json();
    if (!data.success) {
      alert("Error sending message: " + data.message);
    }
  } catch (e) {
    console.error("Failed to send message", e);
  } finally {
    if (sendBtn) sendBtn.disabled = false;
    document.getElementById("message-input").focus();
  }
}

function handleTyping() {
  if (!appState.activeConvId) return;

  // Toggle send/mic icon (accounts for attachments too)
  updateSendButtonState();
  const input = document.getElementById("message-input");

  // Auto-resize textarea (strict reset when empty to prevent snap-back glitch)
  if (!input.value) {
    input.style.height = "42px";
  } else {
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 120) + "px";
  }

  fetch("../api/chat/manage_conversations.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      action: "typing_start",
      conversation_id: appState.activeConvId,
    }),
  });

  clearTimeout(appState.typingTimeout);
  appState.typingTimeout = setTimeout(() => {
    fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "typing_stop",
        conversation_id: appState.activeConvId,
      }),
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

  const menu = document.createElement("div");
  menu.className = "msg-context-menu";
  menu.style.left = `${e.pageX}px`;
  menu.style.top = `${e.pageY}px`;

  // Show Translate only when the message has text content
  const msgForMenu = appState.messages.find((m) => m.id == msgId);
  const canTranslate = !!(msgForMenu && msgForMenu.content && !msgForMenu.deleted_for_all);
  const isTranslated = !!(
    appState.translationCache[msgId] &&
    appState.translationCache[msgId].showingTranslation
  );

  menu.innerHTML = `
        <div class="msg-context-item" onclick="initReply(${msgId})">↩ Reply</div>
        <div class="msg-context-item" onclick="openForwardModal(${msgId})">↪ Forward</div>
        ${canTranslate ? `<div class="msg-context-item" onclick="translateMessage(${msgId})">${isTranslated ? "↺ Show original" : "🌐 Translate"}</div>` : ""}
        <div class="msg-context-item danger" onclick="deleteMessage(${msgId}, 'for_me')">🗑 Delete for me</div>
        ${isOwn ? `<div class="msg-context-item danger" onclick="deleteMessage(${msgId}, 'for_all')">🚫 Delete for everyone</div>` : ""}
    `;

  document.body.appendChild(menu);
  contextMenuNode = menu;

  // Adjust position if offscreen
  const rect = menu.getBoundingClientRect();
  if (rect.bottom > window.innerHeight)
    menu.style.top = `${e.pageY - rect.height}px`;
  if (rect.right > window.innerWidth)
    menu.style.left = `${e.pageX - rect.width}px`;
}

function closeContextMenu() {
  if (contextMenuNode) {
    contextMenuNode.remove();
    contextMenuNode = null;
  }
}

function initReply(msgId) {
  const msg = appState.messages.find((m) => m.id === msgId);
  if (!msg) return;

  appState.replyToId = msgId;
  document.getElementById("reply-bar-sender").textContent =
    msg.sender_id == appState.user.id
      ? "You"
      : `${msg.first_name} ${msg.last_name}`;
  document.getElementById("reply-bar-text").textContent =
    msg.content || (msg.media_url ? "Attachment" : "");
  document.getElementById("reply-bar").style.display = "flex";
  document.getElementById("message-input").focus();
}

function cancelReply() {
  appState.replyToId = null;
  document.getElementById("reply-bar").style.display = "none";
}

async function deleteMessage(msgId, type) {
  if (
    !confirm(
      type === "for_all"
        ? "Delete message for everyone?"
        : "Delete message for yourself?",
    )
  )
    return;

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "delete_message",
        message_id: msgId,
        delete_type: type,
      }),
    });
    const data = await res.json();
    if (data.success) {
      // Optimistic update
      if (type === "for_all") {
        const msg = appState.messages.find((m) => m.id === msgId);
        if (msg) {
          msg.deleted_for_all = 1;
          msg.content = null;
          msg.media_url = null;
        }
      } else {
        appState.messages = appState.messages.filter((m) => m.id !== msgId);
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
  document.getElementById("forward-modal").style.display = "flex";
  document.getElementById("forward-search").value = "";
  filterForwardList();
}

function closeForwardModal() {
  document.getElementById("forward-modal").style.display = "none";
  appState.forwardMessageId = null;
}

function filterForwardList() {
  const term = document.getElementById("forward-search").value.toLowerCase();
  const list = document.getElementById("forward-list");
  let html = "";

  appState.conversations.forEach((c) => {
    if (c.my_status !== "approved") return;
    if (term && !c.name.toLowerCase().includes(term)) return;

    let icon = c.type === "community" ? "🌍" : c.type === "group" ? "👥" : "👤";
    html += `
            <div class="forward-conv-item" onclick="executeForward(${c.id})">
                <div class="forward-conv-icon">${icon}</div>
                <div class="forward-conv-name">${c.name}</div>
            </div>
        `;
  });

  list.innerHTML =
    html ||
    '<div style="padding:20px;text-align:center;color:#64748b;">No matching conversations.</div>';
}

async function executeForward(targetConvId) {
  if (!appState.forwardMessageId) return;

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "forward_message",
        message_id: appState.forwardMessageId,
        target_conversation_id: targetConvId,
      }),
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
    const res = await fetch(
      `../api/chat/get_participants.php?conversation_id=${convId}`,
    );
    const data = await res.json();
    if (data.success && appState.activeConvId === convId) {
      document.getElementById("member-count-text").textContent =
        `${data.count} members (${data.online_count} online)`;
    }
  } catch (e) {
    console.error(e);
  }
}

async function openMembersModal() {
  if (!appState.activeConvId) return;

  try {
    const res = await fetch(
      `../api/chat/get_participants.php?conversation_id=${appState.activeConvId}`,
    );
    const data = await res.json();
    if (!data.success) return;

    // Determine if I am admin in this conversation
    const conv = appState.conversations.find(
      (c) => c.id == appState.activeConvId,
    );
    const iAmAdmin = conv && conv.my_role === "admin";
    const isGroupOrCommunity = conv && conv.type !== "direct";

    document.getElementById("members-modal-count").textContent = data.count;
    const list = document.getElementById("members-modal-list");
    const avatarColors = [
      "#25D366",
      "#7C3AED",
      "#E67E22",
      "#2563EB",
      "#E91E63",
      "#00ACC1",
    ];
    let html = "";

    data.participants.forEach((p) => {
      const isMe = p.user_id == appState.user.id;
      const badge =
        p.system_role === "admin"
          ? '<span class="member-badge-system admin">Admin</span>'
          : p.system_role === "moderator"
            ? '<span class="member-badge-system moderator">Mod</span>'
            : "";

      const chatBadge =
        p.chat_role === "admin"
          ? '<span class="member-badge-chat">Chat Admin</span>'
          : "";
      const statusIndicator = p.is_online
        ? '<span class="online-status-dot online"></span>'
        : "";
      const avColor = avatarColors[p.user_id % avatarColors.length];

      // Show remove button if I am admin, it's a group/community, and it's not myself
      const removeBtn =
        iAmAdmin && isGroupOrCommunity && !isMe
          ? `<button class="member-remove-btn" onclick="removeUser(${appState.activeConvId}, ${p.user_id})" title="Remove user">✖</button>`
          : "";

      html += `
                <div class="members-modal-item">
                    <div class="members-modal-av" style="background: ${avColor}">${escapeHTML(p.first_name).charAt(0)}</div>
                    <div class="members-modal-info">
                        <div class="members-modal-name">${statusIndicator}${escapeHTML(p.first_name)} ${escapeHTML(p.last_name)} ${isMe ? "(You)" : ""}</div>
                        <div class="members-modal-username">@${escapeHTML(p.username)}</div>
                    </div>
                    <div class="members-modal-badges">${badge}${chatBadge}${removeBtn}</div>
                </div>
            `;
    });

    list.innerHTML = html;
    document.getElementById("members-modal").style.display = "flex";
  } catch (e) {
    console.error(e);
  }
}

function closeMembersModal() {
  document.getElementById("members-modal").style.display = "none";
}

async function removeUser(convId, targetUserId) {
  if (!confirm("Remove this user from the conversation?")) return;

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "remove_user",
        conversation_id: convId,
        target_user_id: targetUserId,
      }),
    });
    const data = await res.json();
    if (data.success) {
      alert("User removed successfully.");
      updateMemberCount(convId);
      openMembersModal(); // Re-render the modal with updated list
    } else {
      alert(data.message);
    }
  } catch (e) {
    console.error("Remove user failed", e);
    alert("Failed to remove user.");
  }
}

// ─── Actions (Create, Add, Leave, Delete) ───
let currentAction = null;

function openActionModal(actionType) {
  currentAction = actionType;
  const title = document.getElementById("modal-title");
  const input = document.getElementById("modal-input");

  if (actionType === "direct") {
    title.textContent = "New Direct Message";
    input.placeholder = "Enter username or email...";
  } else if (actionType === "group") {
    title.textContent = "Create New Group";
    input.placeholder = "Enter group name...";
  } else if (actionType === "community") {
    title.textContent = "Create New Community";
    input.placeholder = "Enter community name...";
  } else if (actionType === "add_user") {
    title.textContent = "Add User to Chat";
    input.placeholder = "Enter username or email...";
  }

  input.value = "";
  document.getElementById("action-modal").style.display = "flex";
  input.focus();
}

function closeModal() {
  document.getElementById("action-modal").style.display = "none";
  currentAction = null;
}

document.getElementById("modal-submit").addEventListener("click", async () => {
  const val = document.getElementById("modal-input").value.trim();
  if (!val) return;

  const formData = new URLSearchParams();

  if (currentAction === "direct") {
    formData.append("action", "create_direct");
    formData.append("target_username", val);
  } else if (currentAction === "group") {
    formData.append("action", "create_group");
    formData.append("name", val);
  } else if (currentAction === "community") {
    formData.append("action", "create_community");
    formData.append("name", val);
  } else if (currentAction === "add_user") {
    formData.append("action", "add_user");
    formData.append("conversation_id", appState.activeConvId);
    formData.append("target_username", val);
  }

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: formData,
    });
    const data = await res.json();

    if (data.success) {
      const action = currentAction;
      closeModal();
      fetchConversations();
      if (data.conversation_id) selectConversation(data.conversation_id);
      if (action === "add_user") updateMemberCount(appState.activeConvId);
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
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "leave_conversation",
        conversation_id: appState.activeConvId,
      }),
    });
    const data = await res.json();
    if (data.success) {
      appState.activeConvId = null;
      document.getElementById("chat-input-area").style.display = "none";
      document.getElementById("messages-container").innerHTML =
        '<div class="disabled-state">Select a conversation.</div>';
      document.getElementById("chat-header-actions").style.display = "none";
      document.getElementById("chat-header-name").textContent =
        "Select a conversation";
      document.getElementById("chat-header-status").textContent = "";
      document.getElementById("member-count-btn").style.display = "none";
      fetchConversations();
    } else {
      alert(data.message);
    }
  } catch (e) {
    console.error(e);
  }
}

async function deleteConversation() {
  if (!appState.activeConvId) return;
  if (
    !confirm(
      "WARNING: This will permanently delete the entire conversation for EVERYONE. Continue?",
    )
  )
    return;

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "delete_conversation",
        conversation_id: appState.activeConvId,
      }),
    });
    const data = await res.json();
    if (data.success) {
      appState.activeConvId = null;
      document.getElementById("chat-input-area").style.display = "none";
      document.getElementById("messages-container").innerHTML =
        '<div class="disabled-state">Select a conversation.</div>';
      document.getElementById("chat-header-actions").style.display = "none";
      document.getElementById("chat-header-name").textContent =
        "Select a conversation";
      document.getElementById("chat-header-status").textContent = "";
      document.getElementById("member-count-btn").style.display = "none";
      fetchConversations();
    } else {
      alert(data.message);
    }
  } catch (e) {
    console.error(e);
  }
}

async function joinCommunity(id) {
  const msgContainer = document.getElementById("messages-container");
  const origContent = msgContainer.innerHTML;

  try {
    // Show loading state
    msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:2rem;margin-bottom:10px;">⏳</div>
                <p>Sending join request...</p>
            </div>
        `;

    const res = await fetch("../api/chat/community_requests.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "request_join",
        conversation_id: id,
      }),
    });
    const data = await res.json();

    if (data.success) {
      msgContainer.innerHTML = `
                <div class="disabled-state">
                    <div style="font-size:3rem;margin-bottom:10px;">✅</div>
                    <h3>Join Request Sent!</h3>
                    <p>${escapeHTML(data.message || "Your request has been sent to the community admins.")}</p>
                    <p style="font-size:0.85rem;color:var(--muted);margin-top:15px;">You can view messages once your request is approved.</p>
                </div>
            `;

      // Refresh conversations to update status
      await new Promise((resolve) => setTimeout(resolve, 1500));
      fetchConversations();

      // Show pending status
      selectConversation(id);
    } else {
      msgContainer.innerHTML = `
                <div class="disabled-state">
                    <div style="font-size:3rem;margin-bottom:10px;">⚠️</div>
                    <h3>Request Failed</h3>
                    <p>${escapeHTML(data.message || "Could not send join request.")}</p>
                    <button class="chat-btn-join" onclick="joinCommunity(${id})" style="margin-top:15px;">Try Again</button>
                </div>
            `;
    }
  } catch (e) {
    console.error("Join request error:", e);
    msgContainer.innerHTML = `
            <div class="disabled-state">
                <div style="font-size:3rem;margin-bottom:10px;">❌</div>
                <h3>Connection Error</h3>
                <p>Failed to send join request. Please check your connection.</p>
                <button class="chat-btn-join" onclick="joinCommunity(${id})" style="margin-top:15px;">Try Again</button>
            </div>
        `;
  }
}

// ─── Lightbox ───
function openLightbox(src) {
  const overlay = document.createElement("div");
  overlay.className = "lightbox-overlay";
  overlay.onclick = () => overlay.remove();
  const img = document.createElement("img");
  img.src = src;
  img.alt = "Full screen preview";
  overlay.appendChild(img);
  document.body.appendChild(overlay);
}

// ─── Community Requests (Admin Only) ───
let requestsRefreshInterval = null;

async function fetchRequests() {
  try {
    const res = await fetch(
      "../api/chat/community_requests.php?action=get_requests",
      {
        method: "GET",
      },
    );
    const data = await res.json();

    if (!data.success) {
      console.warn("Failed to fetch requests:", data.message);
      return;
    }

    const list = document.getElementById("requests-list");
    if (!list) return; // If panel closed/not visible

    const count = data.count || 0;

    if (count === 0) {
      list.innerHTML =
        '<div style="color:var(--muted);font-size:0.85rem;padding:10px;">No pending requests.</div>';
      return;
    }

    let html = "";
    data.requests.forEach((req) => {
      const reqDate = new Date(req.request_date).toLocaleString([], {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });

      html += `
                <div class="request-item" id="req-${req.conversation_id}-${req.user_id}">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div>
                            <div style="font-size:0.85rem;font-weight:600;">${escapeHTML(req.first_name)} ${escapeHTML(req.last_name)}</div>
                            <div style="font-size:0.75rem;color:var(--muted);">@${escapeHTML(req.username)}</div>
                        </div>
                        <div style="font-size:0.7rem;color:var(--muted);">${reqDate}</div>
                    </div>
                    <div style="font-size:0.75rem;color:var(--muted);margin:8px 0px;">wants to join <strong>${escapeHTML(req.community_name)}</strong></div>
                    <div class="request-actions">
                        <button class="btn-approve" onclick="handleRequest(${req.conversation_id}, ${req.user_id}, 'approve')" title="Approve request">✓ Approve</button>
                        <button class="btn-reject" onclick="handleRequest(${req.conversation_id}, ${req.user_id}, 'reject')" title="Reject request">✕ Reject</button>
                    </div>
                </div>
            `;
    });

    list.innerHTML = html;
  } catch (e) {
    console.error("Error fetching requests:", e);
    const list = document.getElementById("requests-list");
    if (list) {
      list.innerHTML =
        '<div style="color:#e74c3c;font-size:0.85rem;padding:10px;">Error loading requests</div>';
    }
  }
}

async function handleRequest(convId, userId, action) {
  // Visual feedback
  const itemId = `req-${convId}-${userId}`;
  const item = document.getElementById(itemId);
  if (!item) return;

  const originalHTML = item.innerHTML;
  const buttons = item.querySelectorAll("button");
  buttons.forEach((btn) => (btn.disabled = true));
  item.style.opacity = "0.6";

  try {
    const res = await fetch("../api/chat/community_requests.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "handle_request",
        conversation_id: convId,
        target_user_id: userId,
        status: action === "approve" ? "approved" : "rejected",
      }),
    });
    const data = await res.json();

    if (data.success) {
      // Animate removal
      item.style.transition = "all 0.3s ease-out";
      item.style.opacity = "0";
      item.style.maxHeight = "0px";
      item.style.marginBottom = "0px";
      item.style.overflow = "hidden";

      setTimeout(() => {
        item.remove();

        // Show toast notification
        showToast(
          action === "approve" ? "✓" : "✕",
          data.message ||
            `Request ${action === "approve" ? "approved" : "rejected"}`,
          action === "approve" ? "#25D366" : "#e74c3c",
        );

        // Refresh conversations to update user's status
        fetchConversations();
      }, 300);
    } else {
      // Restore UI on error
      item.style.opacity = "1";
      buttons.forEach((btn) => (btn.disabled = false));
      showToast("⚠️", data.message || "Failed to process request", "#e67e22");
    }
  } catch (e) {
    console.error("Error handling request:", e);
    item.innerHTML = originalHTML;
    item.style.opacity = "1";
    item.querySelectorAll("button").forEach((btn) => (btn.disabled = false));
    showToast("❌", "Connection error. Please try again", "#e74c3c");
  }
}

// Simple toast notification system
function showToast(icon, message, color) {
  const toast = document.createElement("div");
  toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: ${color};
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
        z-index: 9999;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        animation: slideInRight 0.3s ease-out;
    `;
  const iconSpan = document.createElement("span");
  iconSpan.style.fontSize = "1.1rem";
  iconSpan.textContent = icon;

  const messageSpan = document.createElement("span");
  messageSpan.textContent = message;

  toast.appendChild(iconSpan);
  toast.appendChild(messageSpan);

  document.body.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = "slideOutRight 0.3s ease-in";
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Setup auto-refresh for requests (every 30 seconds)
function setupRequestsAutoRefresh() {
  if (requestsRefreshInterval) clearInterval(requestsRefreshInterval);

  // Initial fetch
  fetchRequests();

  // Refresh every 30 seconds
  requestsRefreshInterval = setInterval(() => {
    fetchRequests();
  }, 30000);
}

// Utility: Scroll to message
function scrollToMessage(id) {
  const el = document.getElementById("msg-" + id);
  if (el) {
    el.scrollIntoView({ behavior: "smooth", block: "center" });
    el.style.backgroundColor = "rgba(46,117,182,0.2)";
    setTimeout(() => (el.style.backgroundColor = "transparent"), 1500);
  }
}

// ═══════════════════════════════════════════════
// ─── FEATURE: Blocking System (Direct Messages) ───
// ═══════════════════════════════════════════════

async function checkBlockStatus(targetUserId, forConvId) {
  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "check_block",
        target_user_id: targetUserId,
      }),
    });
    const data = await res.json();
    if (data.success) {
      if (appState.activeConvId !== forConvId) return; // stale — discard
      appState.blockState = {
        i_blocked: data.i_blocked,
        they_blocked: data.they_blocked,
        any_block: data.any_block,
      };
      updateBlockUI();
    }
  } catch (e) {
    console.error("Failed to check block status:", e);
  }
}

function updateBlockUI() {
  const blockBtn = document.getElementById("btn-block-user");
  const blockedOverlay = document.getElementById("blocked-overlay");
  const blockedText = document.getElementById("blocked-overlay-text");
  const inputArea = document.getElementById("chat-input-area");

  if (appState.blockState.i_blocked) {
    // I blocked them
    blockBtn.textContent = "✅ Unblock User";
    blockBtn.className = "block-btn unblock";
    blockedOverlay.style.display = "block";
    blockedText.textContent = "You blocked this user. Unblock to continue messaging.";
    inputArea.style.display = "none";
    clearSmartReplies();
  } else if (appState.blockState.they_blocked) {
    // They blocked me
    blockBtn.style.display = "none"; // Can't unblock someone who blocked you
    blockedOverlay.style.display = "block";
    blockedText.textContent = "You can't send messages to this user.";
    inputArea.style.display = "none";
    clearSmartReplies();
  } else {
    // No block
    blockBtn.textContent = "🚫 Block User";
    blockBtn.className = "block-btn";
    blockedOverlay.style.display = "none";
    // Only show input if the conversation is approved
    const conv = appState.conversations.find(
      (c) => c.id == appState.activeConvId,
    );
    if (conv && conv.my_status === "approved") {
      inputArea.style.display = "flex";
    }
  }
}

async function toggleBlockUser() {
  const conv = appState.conversations.find(
    (c) => c.id == appState.activeConvId,
  );
  if (!conv || conv.type !== "direct" || !conv.other_user_id) return;

  const isBlocking = !appState.blockState.i_blocked;
  const action = isBlocking ? "block_user" : "unblock_user";

  if (
    isBlocking &&
    !confirm(
      "Block this user? They won't be able to send you messages and you won't be able to send them messages.",
    )
  ) {
    return;
  }

  try {
    const res = await fetch("../api/chat/manage_conversations.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: action,
        target_user_id: conv.other_user_id,
      }),
    });
    const data = await res.json();
    if (data.success) {
      showToast(
        isBlocking ? "🚫" : "✅",
        data.message,
        isBlocking ? "#e74c3c" : "#22c55e",
      );
      // Re-check block status to update UI
      await checkBlockStatus(conv.other_user_id, appState.activeConvId);
    } else {
      alert(data.message);
    }
  } catch (e) {
    console.error("Block/unblock failed:", e);
    alert("Failed to update block status.");
  }
}

// ═══════════════════════════════════════════════
// ─── FEATURE: Group Chat Polls ───
// ═══════════════════════════════════════════════

function openPollModal() {
  if (!appState.activeConvId) {
    showToast("⚠️", "Open a group or community first", "#e67e22");
    return;
  }

  // Guard: polls only in groups/communities
  const conv = appState.conversations.find(
    (c) => c.id == appState.activeConvId,
  );
  if (!conv || (conv.type !== "group" && conv.type !== "community")) {
    showToast("⚠️", "Polls are only available in groups and communities", "#e67e22");
    return;
  }

  const modal = document.getElementById("poll-modal");
  if (!modal) {
    console.error("openPollModal: #poll-modal not found in DOM");
    alert("Poll dialog is missing from the page. Please refresh.");
    return;
  }

  // Move modal to <body> so it can never be clipped by ancestor stacking contexts
  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }
  modal.style.display = "flex";
  modal.style.zIndex = "10000";

  // Reset form
  const qInput = document.getElementById("poll-question-input");
  const container = document.getElementById("poll-options-container");
  if (qInput) qInput.value = "";
  if (container) {
    container.innerHTML = `
      <div class="poll-option-row">
        <input type="text" class="poll-option-input poll-opt" placeholder="Option 1" maxlength="255" />
      </div>
      <div class="poll-option-row">
        <input type="text" class="poll-option-input poll-opt" placeholder="Option 2" maxlength="255" />
      </div>
    `;
  }

  // Focus the question input
  setTimeout(() => {
    const q = document.getElementById("poll-question-input");
    if (q) q.focus();
  }, 100);
}

function closePollModal() {
  document.getElementById("poll-modal").style.display = "none";
}

function addPollOption() {
  const container = document.getElementById("poll-options-container");
  const optCount = container.querySelectorAll(".poll-opt").length;
  if (optCount >= 10) {
    showToast("⚠️", "Maximum 10 options allowed", "#e67e22");
    return;
  }

  const row = document.createElement("div");
  row.className = "poll-option-row";
  row.innerHTML = `
    <input type="text" class="poll-option-input poll-opt" placeholder="Option ${optCount + 1}" maxlength="255" />
    <button class="poll-option-remove" onclick="removePollOption(this)" title="Remove option">✕</button>
  `;
  container.appendChild(row);
  row.querySelector("input").focus();
}

function removePollOption(btn) {
  const container = document.getElementById("poll-options-container");
  const rows = container.querySelectorAll(".poll-option-row");
  if (rows.length <= 2) {
    showToast("⚠️", "A poll needs at least 2 options", "#e67e22");
    return;
  }
  btn.closest(".poll-option-row").remove();
}

async function submitPoll() {
  const question = document.getElementById("poll-question-input").value.trim();
  const optInputs = document.querySelectorAll("#poll-options-container .poll-opt");
  const options = [];

  optInputs.forEach((inp) => {
    const val = inp.value.trim();
    if (val) options.push(val);
  });

  if (!question) {
    showToast("⚠️", "Please enter a question", "#e67e22");
    document.getElementById("poll-question-input").focus();
    return;
  }

  if (options.length < 2) {
    showToast("⚠️", "Please add at least 2 options", "#e67e22");
    return;
  }

  // Disable button to prevent double-send
  const submitBtn = document.getElementById("poll-submit-btn");
  submitBtn.disabled = true;
  submitBtn.textContent = "Sending...";

  try {
    const formData = new FormData();
    formData.append("conversation_id", appState.activeConvId);
    formData.append("poll_question", question);
    options.forEach((opt) => formData.append("poll_options[]", opt));

    const res = await fetch("../api/chat/send_message.php", {
      method: "POST",
      body: formData,
    });
    const data = await res.json();

    if (data.success) {
      closePollModal();
      showToast("📊", "Poll created!", "#2e75b6");
    } else {
      alert("Error creating poll: " + data.message);
    }
  } catch (e) {
    console.error("Failed to create poll:", e);
    alert("Failed to create poll.");
  } finally {
    submitBtn.disabled = false;
    submitBtn.textContent = "Send Poll";
  }
}

async function fetchPollData(messageId) {
  appState.pollFetchInFlight[messageId] = true;
  try {
    const res = await fetch(
      `../api/chat/polls.php?message_id=${messageId}`,
    );
    const data = await res.json();

    if (data.success && data.poll) {
      // Cache the poll data so subsequent renders skip the fetch
      appState.pollCache[messageId] = data.poll;
      renderPollBubble(messageId, data.poll);
    }
  } catch (e) {
    console.error("Failed to fetch poll data:", e);
  } finally {
    delete appState.pollFetchInFlight[messageId];
  }
}

// Build poll bubble HTML string (used by both cache-hit rendering and DOM patching)
function buildPollBubbleHtml(messageId, poll) {
  let optionsHtml = "";
  poll.options.forEach((opt) => {
    const pct =
      poll.total_votes > 0
        ? Math.round((opt.vote_count / poll.total_votes) * 100)
        : 0;
    const isVoted = poll.my_vote_option_id === opt.option_id;

    optionsHtml += `
      <div class="poll-option-item ${isVoted ? "voted" : ""}"
           onclick="votePoll(${messageId}, ${opt.option_id})">
        <div class="poll-option-bar" style="width: ${pct}%"></div>
        <div class="poll-option-content">
          <div class="poll-option-text">
            <span class="poll-option-check">${isVoted ? "✓" : ""}</span>
            ${escapeHTML(opt.option_text)}
          </div>
          <span class="poll-option-count">${opt.vote_count} vote${opt.vote_count !== 1 ? "s" : ""} · ${pct}%</span>
        </div>
      </div>
    `;
  });

  return `<div class="poll-bubble" id="poll-bubble-${messageId}">
    <div class="poll-bubble-question">📊 ${escapeHTML(poll.question)}</div>
    ${optionsHtml}
    <div class="poll-total">${poll.total_votes} total vote${poll.total_votes !== 1 ? "s" : ""}</div>
  </div>`;
}

function renderPollBubble(messageId, poll) {
  const bubble = document.getElementById(`poll-bubble-${messageId}`);
  if (!bubble) return;

  // Re-use the shared builder, but only inject innerHTML (bubble div already exists)
  let optionsHtml = "";
  poll.options.forEach((opt) => {
    const pct =
      poll.total_votes > 0
        ? Math.round((opt.vote_count / poll.total_votes) * 100)
        : 0;
    const isVoted = poll.my_vote_option_id === opt.option_id;

    optionsHtml += `
      <div class="poll-option-item ${isVoted ? "voted" : ""}"
           onclick="votePoll(${messageId}, ${opt.option_id})">
        <div class="poll-option-bar" style="width: ${pct}%"></div>
        <div class="poll-option-content">
          <div class="poll-option-text">
            <span class="poll-option-check">${isVoted ? "✓" : ""}</span>
            ${escapeHTML(opt.option_text)}
          </div>
          <span class="poll-option-count">${opt.vote_count} vote${opt.vote_count !== 1 ? "s" : ""} · ${pct}%</span>
        </div>
      </div>
    `;
  });

  bubble.innerHTML = `
    <div class="poll-bubble-question">📊 ${escapeHTML(poll.question)}</div>
    ${optionsHtml}
    <div class="poll-total">${poll.total_votes} total vote${poll.total_votes !== 1 ? "s" : ""}</div>
  `;
}

async function votePoll(messageId, optionId) {
  try {
    const res = await fetch("../api/chat/polls.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        message_id: messageId,
        option_id: optionId,
      }),
    });
    const data = await res.json();

    if (data.success) {
      // Invalidate cache so the next fetch gets fresh vote counts
      delete appState.pollCache[messageId];
      await fetchPollData(messageId);
    } else {
      showToast("⚠️", data.message || "Failed to vote", "#e67e22");
    }
  } catch (e) {
    console.error("Failed to vote:", e);
    showToast("❌", "Connection error", "#e74c3c");
  }
}

// ═══════════════════════════════════════════════
// ─── FEATURE: AI Smart Replies ───
// Suggests 3 short replies based on the most recent incoming message.
// ═══════════════════════════════════════════════

function clearSmartReplies() {
  const bar = document.getElementById("smart-replies-bar");
  const chips = document.getElementById("smart-replies-chips");
  if (chips) chips.innerHTML = "";
  if (bar) bar.style.display = "none";
  if (appState.smartRepliesAbort) {
    try { appState.smartRepliesAbort.abort(); } catch (_) {}
    appState.smartRepliesAbort = null;
  }
  appState.smartRepliesForConvId = null;
}

function renderSmartReplyChips(replies) {
  const bar = document.getElementById("smart-replies-bar");
  const chips = document.getElementById("smart-replies-chips");
  if (!bar || !chips) return;

  if (!Array.isArray(replies) || replies.length === 0) {
    bar.style.display = "none";
    chips.innerHTML = "";
    return;
  }

  chips.innerHTML = replies
    .map(
      (r) =>
        `<button class="smart-reply-chip" type="button" onclick="sendSmartReply(this)">${escapeHTML(
          r,
        )}</button>`,
    )
    .join("");
  bar.style.display = "flex";
}

async function fetchSmartReplies(force = false) {
  const convId = appState.activeConvId;
  if (!convId) {
    clearSmartReplies();
    return;
  }

  // Only suggest in approved conversations
  const conv = appState.conversations.find((c) => c.id == convId);
  if (!conv || conv.my_status !== "approved") {
    clearSmartReplies();
    return;
  }

  // Suppress while blocked
  if (appState.blockState && appState.blockState.any_block) {
    clearSmartReplies();
    return;
  }

  // Skip while user is mid-compose (don't overwrite a draft they're typing)
  if (!force) {
    const input = document.getElementById("message-input");
    if (input && input.value.trim().length > 0) return;
  }

  if (appState.smartRepliesInFlight) return;
  appState.smartRepliesInFlight = true;

  // Cancel any in-flight previous request
  if (appState.smartRepliesAbort) {
    try { appState.smartRepliesAbort.abort(); } catch (_) {}
  }
  appState.smartRepliesAbort = new AbortController();

  const bar = document.getElementById("smart-replies-bar");
  const chips = document.getElementById("smart-replies-chips");
  const refresh = document.getElementById("smart-replies-refresh");
  if (bar && chips) {
    bar.style.display = "flex";
    chips.innerHTML = `<span class="smart-replies-empty">Thinking…</span>`;
  }
  if (refresh) refresh.classList.add("spinning");

  try {
    const res = await fetch("../api/chat/ai_assist.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "smart_replies",
        conversation_id: convId,
      }),
      signal: appState.smartRepliesAbort.signal,
    });
    const data = await res.json();

    // Drop stale responses if the user switched conversations meanwhile
    if (appState.activeConvId != convId) return;

    if (data.success) {
      appState.smartRepliesForConvId = convId;
      if (!data.replies || data.replies.length === 0) {
        clearSmartReplies();
      } else {
        renderSmartReplyChips(data.replies);
      }
    } else {
      console.warn("smart_replies failed:", data.message);
      clearSmartReplies();
    }
  } catch (e) {
    if (e.name !== "AbortError") {
      console.error("smart_replies error:", e);
    }
    clearSmartReplies();
  } finally {
    appState.smartRepliesInFlight = false;
    if (refresh) refresh.classList.remove("spinning");
  }
}

// Called by the chip's onclick. Sets the input value, then sends.
function sendSmartReply(chipEl) {
  const text = chipEl ? chipEl.textContent.trim() : "";
  if (!text) return;
  const input = document.getElementById("message-input");
  if (!input) return;
  input.value = text;
  // Trigger normal send pipeline (handles typing-stop, receipts, etc.)
  if (typeof updateSendButtonState === "function") updateSendButtonState();
  sendMessage();
  // Chips will be re-fetched after send completes (sendMessage clears them);
  // hide immediately for responsiveness.
  clearSmartReplies();
}

// ═══════════════════════════════════════════════
// ─── FEATURE: AI Translate Message ───
// Inline translate in the context menu. Toggles between original and translation.
// ═══════════════════════════════════════════════

async function translateMessage(msgId) {
  closeContextMenu();
  if (appState.translationInFlight[msgId]) return;

  const msg = appState.messages.find((m) => m.id == msgId);
  if (!msg || !msg.content) {
    showToast("⚠️", "Nothing to translate", "#e67e22");
    return;
  }

  const bubble = document.querySelector(
    `[data-message-id="${msgId}"] .msg-content`,
  );
  if (!bubble) {
    showToast("⚠️", "Could not find message", "#e67e22");
    return;
  }

  // Toggle off if already translated
  const cached = appState.translationCache[msgId];
  if (cached && cached.showingTranslation) {
    bubble.innerHTML = escapeHTML(cached.original);
    // Remove the badge if present
    const tag = document.querySelector(`#ai-tag-${msgId}`);
    if (tag) tag.remove();
    cached.showingTranslation = false;
    return;
  }

  // If cached, re-show without hitting backend
  if (cached && cached.translated) {
    applyTranslationToBubble(msgId, cached.translated);
    cached.showingTranslation = true;
    return;
  }

  appState.translationInFlight[msgId] = true;
  const originalHTML = bubble.innerHTML;
  bubble.innerHTML = `<span style="opacity:0.6;font-style:italic;">Translating…</span>`;

  try {
    const res = await fetch("../api/chat/ai_assist.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "translate",
        message_id: msgId,
        target_lang: "English",
      }),
    });
    const data = await res.json();
    if (data.success && data.translated) {
      appState.translationCache[msgId] = {
        original: data.original,
        translated: data.translated,
        target_lang: data.target_lang,
        showingTranslation: true,
      };
      applyTranslationToBubble(msgId, data.translated);
    } else {
      bubble.innerHTML = originalHTML;
      showToast("⚠️", data.message || "Translation failed", "#e67e22");
    }
  } catch (e) {
    console.error("translate error:", e);
    bubble.innerHTML = originalHTML;
    showToast("❌", "Translation failed", "#e74c3c");
  } finally {
    delete appState.translationInFlight[msgId];
  }
}

function applyTranslationToBubble(msgId, translated) {
  const bubble = document.querySelector(
    `[data-message-id="${msgId}"] .msg-content`,
  );
  if (!bubble) return;
  bubble.innerHTML = escapeHTML(translated);

  // Append "Translated by AI" badge if not already there
  if (!document.getElementById(`ai-tag-${msgId}`)) {
    const tag = document.createElement("span");
    tag.id = `ai-tag-${msgId}`;
    tag.className = "ai-translated-tag";
    tag.textContent = "✨ Translated by AI — show original";
    tag.title = "Click to show the original";
    tag.onclick = (e) => {
      e.stopPropagation();
      translateMessage(msgId);
    };
    bubble.parentElement.appendChild(tag);
  }
}
