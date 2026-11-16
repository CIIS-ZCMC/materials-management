# Sharing the App on Local Network (LAN / Wi-Fi)

This guide explains how to run the system so your office mates on the same Wi-Fi or LAN can access it through your laptop's IPv4 address.

**Your laptop's IPv4:** `10.10.175.89`
*(If this changes, update it everywhere it appears below: the URLs, `vite.config.ts`, and `.env`.)*

---

## Contents

1. [Which method should I use?](#1-which-method-should-i-use)
2. [One-time setup](#2-one-time-setup)
3. [Method A: Working alone (localhost)](#3-method-a-working-alone-localhost)
4. [Method B: Quick Share (recommended for testing)](#4-method-b-quick-share-recommended-for-testing)
5. [Method C: Live Dev Share](#5-method-c-live-dev-share)
6. [Troubleshooting](#6-troubleshooting)

---

## 1. Which method should I use?

| Situation | Method | Command(s) | Share with mate? |
|---|---|---|---|
| Working by yourself | **A: Localhost** | `composer dev` | No |
| Mate just needs to view/test the app | **B: Quick Share** | `npm run build` + `php artisan serve` | Yes |
| You're coding while your mate watches | **C: Live Dev Share** | `php artisan serve` + `npm run dev` (2 terminals) | Yes |

> ### ⚠️ The `composer dev` rule
> `composer dev` **only works for you, locally.** It binds to `localhost` (127.0.0.1).
> - You **cannot** use `http://10.10.175.89:8000` while it is running.
> - Your office mate **cannot** connect (they'll see `ERR_CONNECTION_REFUSED`).
>
> **To share the app, use Method B or C instead of `composer dev`.**

---

## 2. One-time setup

Do these before sharing for the first time (and re-check them if your IP changes).

### 2.1 `.env` settings

To prevent the **419 Page Expired** error, make sure `.env` contains:

```env
APP_URL=http://10.10.175.89:8000
SESSION_DOMAIN=null
```

After any change to `.env`, run:

```powershell
php artisan optimize:clear
```

### 2.2 Windows Firewall

Allow inbound traffic so your mate can reach your laptop:

1. Open **Windows Defender Firewall → Advanced Settings → Inbound Rules**.
2. Allow **TCP ports `8000` and `5173`**.
3. Set your network profile to **Private** in Windows Wi-Fi settings.

### 2.3 `vite.config.ts` (Method C only)

Set the `hmr` block to your IP:

```ts
server: {
    host: '0.0.0.0',
    hmr: {
        host: '10.10.175.89', // Must match your current IP
    },
    // ...
}
```

---

## 3. Method A: Working alone (localhost)

Use this when you don't need to share your screen.

```powershell
composer dev
```

Open: <http://localhost:8000>

---

## 4. Method B: Quick Share (recommended for testing)

Use this when your mate just needs to look at the app right now and you don't need live code updates.

1. Stop all running terminals.
2. Build the frontend:
   ```powershell
   npm run build  or  npm run dev -- --host
   ```
3. Start the server:
   ```powershell
   php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Tell your mate to open: **<http://10.10.175.89:8000>**

> **Note:** Re-run `npm run build` every time you change React code.

---

## 5. Method C: Live Dev Share

Use this when you want to share the app **and** have the React frontend auto-reload as you edit code. This needs **two separate terminals**.

> Make sure `vite.config.ts` is set up first (see [2.3](#23-viteconfigts-method-c-only)).

**Terminal 1: Laravel backend**
```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

**Terminal 2: Vite frontend**
```powershell
npm run dev -- --host
```

Your mate opens: **<http://10.10.175.89:8000>**

---

## 6. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **"419 Page Expired"** when logging in | Laravel's security token is mismatched from switching between `localhost` and `10.10.175.89`. | Clear browser cookies for `10.10.175.89:8000`, or use an Incognito window. Also confirm `hmr.host` in `vite.config.ts` is your IP. |
| **Black screen / no CSS** on your mate's browser | Vite isn't running with `--host`, or `hmr.host` in `vite.config.ts` is `localhost`. | Use Method B (`npm run build`), or fix your Vite config. |
| **Mate can't connect at all** | Windows Firewall is blocking the ports. | See [2.2 Windows Firewall](#22-windows-firewall): allow TCP `8000` and `5173`, and set the network to Private. |
| **"Network Error" / timeout** on mate's PC | Client Isolation (common on hospital/office Wi-Fi) blocks laptop-to-laptop traffic. | Ask IT whether the network blocks it. Test by having your mate run `ping 10.10.175.89` in Command Prompt. If it fails, the network is blocking you. |

### Quick checklist when something fails

- [ ] Not using `composer dev` while sharing
- [ ] IP in `.env`, `vite.config.ts`, and the URL matches your current IPv4 (`ipconfig`)
- [ ] Ran `php artisan optimize:clear` after editing `.env`
- [ ] Firewall allows ports `8000` and `5173`; network profile is Private
- [ ] Mate can `ping 10.10.175.89`