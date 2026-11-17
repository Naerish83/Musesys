import os
import re

TARGET_DIR = r"c:\Projects\UACP\Unified\data\musesys-mega"
OUT_FILE = r"C:\Users\chenderson\.gemini\antigravity\brain\f8336aad-f800-4e9f-8330-1cd97a277c2e\codebase_graph.md"

def scan():
    files = []
    for root, dirs, filenames in os.walk(TARGET_DIR):
        for f in filenames:
            if f.endswith('.php') or f.endswith('.js'):
                files.append(os.path.join(root, f))
    
    symbols = {} # name -> {type, file, desc}
    hooks = [] # (hook, callback, file, hook_type)
    calls = [] # (caller, callee, relation_type)
    
    # Regexes
    re_func = re.compile(r'function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(')
    re_class = re.compile(r'class\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)')
    re_define = re.compile(r'define\(\s*[\'"]([A-Z0-9_]+)[\'"]')
    re_const = re.compile(r'const\s+([A-Z0-9_]+)\s*=')
    
    # We match: add_action('hook_name', 'callback_name')
    # Or add_action('hook_name', array($this, 'callback_name'))
    re_hook = re.compile(r'add_(action|filter)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(?:[\'"]([^\'"]+)[\'"]|array\([^,]+,\s*[\'"]([^\'"]+)[\'"]\))')

    # 1. First pass: find all definitions
    for path in files:
        rel_path = os.path.relpath(path, TARGET_DIR).replace('\\', '/')
        with open(path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
        
        for m in re_func.finditer(content):
            name = m.group(1)
            desc = "General function logic"
            if "_get_" in name: desc = "Retrieves data"
            elif "_record_" in name or "_log_" in name: desc = "Persistence/Logging"
            elif "_block_" in name or "_is_blocked_" in name: desc = "Blocking/Check Logic"
            elif "activate" in name: desc = "Plugin Activation"
            elif "init" in name: desc = "Initialization"

            symbols[name] = {"type": "function", "file": rel_path, "desc": desc}
            
        for m in re_class.finditer(content):
            symbols[m.group(1)] = {"type": "class", "file": rel_path, "desc": "Class Definition"}
            
        for m in re_define.finditer(content):
            symbols[m.group(1)] = {"type": "constant", "file": rel_path, "desc": "Global config constant"}
            
        for m in re_const.finditer(content):
            symbols[m.group(1)] = {"type": "constant", "file": rel_path, "desc": "Class constant"}
            
        for m in re_hook.finditer(content):
            hook_type = m.group(1)
            hook_name = m.group(2)
            callback = m.group(3) or m.group(4)
            if callback:
                hooks.append((hook_name, callback, rel_path, hook_type))
                if hook_name not in symbols:
                    symbols[hook_name] = {"type": "hook", "file": rel_path, "desc": f"WordPress {hook_type.title()}"}

    # 2. Second pass: find usages
    for path in files:
        with open(path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
            
        # extract loose function blocks
        blocks = []
        for match in re.finditer(r'function\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*\(', content):
            blocks.append((match.group(1), match.end()))
            
        for i in range(len(blocks)):
            func_name, start_idx = blocks[i]
            end_idx = blocks[i+1][1] if i + 1 < len(blocks) else len(content)
            body = content[start_idx:end_idx]
            
            # Check what this function uses
            for sym, data in symbols.items():
                if sym == func_name: continue
                # Exact word boundary
                if sym in body and re.search(r'\b' + re.escape(sym) + r'\b', body):
                    if data["type"] == "constant":
                        calls.append((func_name, sym, "USES_CONSTANT"))
                    elif data["type"] == "function":
                        calls.append((func_name, sym, "CALLS"))
                    elif data["type"] == "class":
                        calls.append((func_name, sym, "REFERENCES"))

    # 3. Build Markdown
    md = []
    md.append("# MuseSys Mega Codebase Architecture\n")
    md.append("> *The rug really tied the codebase together.*\n\n")

    # Core Security Flow
    md.append("## Core Security Flow\n")
    md.append("When a login request hits `wp-login.php`, the `musesys-login-guard` module hooks into the auth lifecycle in the following strict order:\n")
    md.append("1. **`login_init`** → `muse_guard_early_intercept()`: Instantly drops connection (403 or sleep) if the IP is entirely banned, or if the request is a POST using a forbidden username.\n")
    md.append("2. **`wp_authenticate`** → `muse_guard_pre_auth_attempt()`: Fires before WordPress even evaluates the password. Increments the IP attempt counter early to prevent race conditions during auth processing.\n")
    md.append("3. **`authenticate` (priority 5)** → `muse_guard_check_username_blocklist()`: Final check ensuring the parsed username isn't on the blocklist.\n")
    md.append("4. **`authenticate` (priority 6)** → `muse_guard_check_ip_block()`: Final check ensuring the IP hasn't been flagged during the same request cycle.\n")
    md.append("5. **`wp_login_failed`** → `muse_guard_record_failed_attempt()`: Increments the brute-force counter upon a proven bad password.\n")
    md.append("6. **`wp_login`** → `muse_guard_clear_attempts_on_success()`: If login succeeds, it wipes the temporary failed attempt tallies for that IP.\n\n")

    # Mermaid Graph
    md.append("## Relational Diagram\n")
    md.append("```mermaid\ngraph TD\n")
    
    # Priority check: we only want to graph things related to 'muse', and WP hooks they touch, to avoid noise.
    def is_priority(n):
        return 'muse' in n.lower() or n.startswith('wp_') or n in ['authenticate', 'login_init']

    filtered_hooks = [h for h in hooks if is_priority(h[0]) or is_priority(h[1])]
    filtered_calls = [c for c in calls if is_priority(c[0]) and is_priority(c[1])]

    edges = set()
    node_defs = {}

    def add_node(name, ntype):
        if name not in node_defs:
            prefix = "F_" if ntype == "function" else "K_" if ntype == "constant" else "H_" if ntype == "hook" else "C_" if ntype=="class" else "N_"
            nid = f"{prefix}{name}"
            node_defs[name] = nid
            md.append(f'  {nid}["{ntype[0].upper()}: {name}"]')
        return node_defs[name]

    # Pre-declare file nodes? The graph gets messy if we map files. Let's map definitions strictly relevant
    
    for hook_name, callback, file_path, hook_type in filtered_hooks:
        h_id = add_node(hook_name, "hook")
        c_id = add_node(callback, symbols.get(callback, {}).get("type", "function"))
        edges.add(f"  {h_id} -->|HOOKS| {c_id}")

    for caller, callee, rtype in filtered_calls:
        c1 = add_node(caller, symbols.get(caller, {}).get("type", "function"))
        c2 = add_node(callee, symbols.get(callee, {}).get("type", "function"))
        edges.add(f"  {c1} -->|{rtype}| {c2}")

    for e in sorted(list(edges)):
        md.append(e)

    md.append("```\n\n")

    # Key Symbols Index
    md.append("## Key Symbols Index\n")
    md.append("| Name | Type | File Definition | Description |\n")
    md.append("|---|---|---|---|\n")
    for sym, data in sorted(symbols.items()):
        if is_priority(sym):
            md.append(f"| `{sym}` | {data['type']} | `{data['file']}` | {data['desc']} |\n")

    # Extension Points
    md.append("\n## Extension Points\n")
    md.append("Functions safe to extend or wrap:\n")
    for sym, data in symbols.items():
        if "get" in sym and data['type'] == 'function' and is_priority(sym):
            md.append(f"- `{sym}`: Data retrieval, safe to intercept or wrap.\n")
            
    md.append("\nHooks that could be added/used for future capabilities:\n")
    md.append("- **Action `wp_login_failed`**: Perfect for sending alerting webhooks (e.g., Slack) on brute force attempts.\n")
    md.append("- **Filter `authenticate`**: Can execute external IP reputation lookups before local blocking.\n")
    md.append("- **Action `user_register`**: Add custom onboarding logic outside main auth flow.\n")
    
    # Ambiguous References
    md.append("\n## Ambiguous References\n")
    md.append("The codebase includes some dynamic elements that defy static parsing:\n")
    md.append("- Use of `$_SERVER['REQUEST_URI']` for route matching in `musesys-login-guard.php` (xmlrpc block).\n")
    md.append("- Magic includes: `musesys_discover_modules()` dynamically includes all `.php` files in `modules/`, making static file dependencies ambiguous without runtime state.\n")

    with open(OUT_FILE, 'w', encoding='utf-8') as f:
        f.write("\n".join(md))

if __name__ == "__main__":
    scan()
    print("Done generating Obsidian artifact.")
