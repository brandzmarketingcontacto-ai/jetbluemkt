package com.diamantesexpress.giftsim;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.util.HashSet;
import java.util.Locale;
import java.util.Set;
import java.util.UUID;

/**
 * DX Gift Simulator: aplicación FICTICIA para validar DiamantesExpress Agent en BlueStacks o LDPlayer.
 * No tiene permisos de red ni realiza compras o envíos reales. Reproduce las pantallas de
 * src/dxagent/android/mock_device.py (mismos resource-id y textos):
 *   inicio -> agregar amigo -> perfil -> solicitud enviada
 *   inicio -> destinatario (solo amigos) -> pestañas pase/caja/token -> confirmación con -/+ -> resultado
 * Amigos, solicitudes pendientes y saldo de diamantes ficticio se guardan en SharedPreferences.
 * Extras de intent (solo para pruebas con adb):
 *   --es outcome success|failure|uncertain   resultado del envío
 *   --ez accept_friends true                 el "cliente" acepta las solicitudes pendientes
 *   --ez require_friendship false            permite regalar sin amistad (como la v1.0)
 *   --ei balance 1000000                     fija el saldo ficticio de diamantes
 */
public class MainActivity extends Activity {
    // kind, texto visible, pestaña, diamantes por unidad, máximo por envío (igual que SIM_PRODUCTS).
    private static final Object[][] PRODUCTS = {
        {"pase_booyah", "Pase Booyah Premium", "pase", 300, 1},
        {"DIAMANTES_100", "100 Diamantes", "pase", 10, 1},
        {"DIAMANTES_310", "310 Diamantes", "pase", 10, 1},
        {"DIAMANTES_520", "520 Diamantes", "pase", 10, 1},
        {"DIAMANTES_1060", "1060 Diamantes", "pase", 10, 1},
        {"caja_tokens_evo_universales", "Caja de Tokens evo universales", "caja", 50, 57},
        {"token_escorpion", "Token Escorpion", "token", 10, 99},
    };
    private static final String[] TABS = {"pase", "caja", "token"};

    private LinearLayout root;
    private SharedPreferences prefs;
    private String playerId = "";
    private String friendTarget = "";
    private Object[] product;
    private String tab = "pase";
    private int quantity = 1;
    private String outcome = "success";
    private boolean requireFriendship = true;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        prefs = getSharedPreferences("giftsim", MODE_PRIVATE);
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 48, 48, 48);
        root.setBackgroundColor(Color.rgb(12, 14, 22));
        setContentView(root);
        applyExtras(getIntent());
        showHome();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        applyExtras(intent);
    }

    private void applyExtras(Intent intent) {
        String extra = intent.getStringExtra("outcome");
        if (extra != null) outcome = extra;
        requireFriendship = intent.getBooleanExtra("require_friendship", requireFriendship);
        if (intent.hasExtra("balance")) prefs.edit().putInt("balance", intent.getIntExtra("balance", 0)).apply();
        if (intent.getBooleanExtra("accept_friends", false)) {
            Set<String> friends = set("friends");
            friends.addAll(set("pending"));
            prefs.edit().putStringSet("friends", friends).putStringSet("pending", new HashSet<>()).apply();
        }
    }

    private Set<String> set(String key) {
        return new HashSet<>(prefs.getStringSet(key, new HashSet<>()));
    }

    private int balance() {
        return prefs.getInt("balance", 1_000_000);
    }

    private void reset() {
        root.removeAllViews();
        text(R.id.app_title, "DX Gift Simulator", 22);
        text(R.id.tv_balance, String.format(Locale.US, "Diamantes: %,d", balance()), 14);
    }

    private TextView text(int id, String value, int size) {
        TextView tv = new TextView(this);
        tv.setId(id);
        tv.setText(value);
        tv.setTextSize(size);
        tv.setTextColor(Color.rgb(235, 238, 245));
        tv.setPadding(0, 16, 0, 16);
        root.addView(tv);
        return tv;
    }

    private TextView error(int id, String value) {
        TextView tv = text(id, value, 14);
        tv.setTextColor(Color.rgb(255, 93, 108));
        return tv;
    }

    private Button button(int id, String value, View.OnClickListener listener) {
        Button b = new Button(this);
        b.setId(id);
        b.setText(value);
        b.setAllCaps(false);
        b.setOnClickListener(listener);
        root.addView(b);
        return b;
    }

    private EditText numberField(int id) {
        EditText field = new EditText(this);
        field.setId(id);
        field.setInputType(InputType.TYPE_CLASS_NUMBER);
        field.setTextColor(Color.BLACK);
        field.setBackgroundColor(Color.rgb(230, 230, 235));
        root.addView(field);
        return field;
    }

    private int productId(String code) {
        return getResources().getIdentifier("product_" + code, "id", getPackageName());
    }

    private int tabId(String name) {
        return getResources().getIdentifier("tab_" + name, "id", getPackageName());
    }

    // ------------------------------------------------------------------ inicio
    private void showHome() {
        reset();
        text(R.id.tv_home, "Simulador de regalos (sin envíos reales)", 16);
        button(R.id.btn_start, "Enviar regalo", v -> showRecipient(null, false));
        button(R.id.btn_add_friend, "Agregar amigo", v -> showAddFriend(null));
    }

    // ----------------------------------------------------------------- amistad
    private void showAddFriend(String err) {
        reset();
        text(R.id.tv_add_friend_label, "Buscar jugador por ID", 16);
        EditText field = numberField(R.id.field_friend_id);
        button(R.id.btn_friend_search, "Buscar", v -> {
            String value = field.getText().toString().trim();
            if (value.matches("[0-9]{5,15}")) {
                friendTarget = value;
                showProfile();
            } else {
                showAddFriend("Jugador no encontrado");
            }
        });
        if (err != null) error(R.id.tv_error, err);
    }

    private void showProfile() {
        reset();
        text(R.id.tv_profile_id, friendTarget, 16);
        if (set("friends").contains(friendTarget)) {
            text(R.id.tv_already_friends, "Ya son amigos", 16);
        } else {
            button(R.id.btn_send_request, "Enviar solicitud", v -> {
                Set<String> pending = set("pending");
                pending.add(friendTarget);
                prefs.edit().putStringSet("pending", pending).apply();
                showRequestSent();
            });
        }
    }

    private void showRequestSent() {
        reset();
        text(R.id.tv_request_sent, "Solicitud enviada", 16);
        text(R.id.tv_request_target, friendTarget, 16);
        button(R.id.btn_done, "Finalizar", v -> showHome());
    }

    // ------------------------------------------------------------------ regalo
    private void showRecipient(String err, boolean notFriend) {
        reset();
        text(R.id.tv_recipient_label, "ID del jugador destinatario", 16);
        EditText field = numberField(R.id.field_player_id);
        button(R.id.btn_search, "Buscar jugador", v -> {
            String value = field.getText().toString().trim();
            if (!value.matches("[0-9]{5,15}")) {
                showRecipient("Jugador no encontrado", false);
            } else if (requireFriendship && !set("friends").contains(value)) {
                showRecipient(null, true);
            } else {
                playerId = value;
                tab = "pase";
                showGifts();
            }
        });
        if (notFriend) error(R.id.tv_not_friend, "Este jugador no es tu amigo");
        else if (err != null) error(R.id.tv_error, err);
    }

    private void showGifts() {
        reset();
        text(R.id.tv_recipient, "Destinatario: " + playerId, 16);
        LinearLayout tabs = new LinearLayout(this);
        tabs.setOrientation(LinearLayout.HORIZONTAL);
        for (String t : TABS) {
            Button b = new Button(this);
            b.setId(tabId(t));
            b.setText(t.toUpperCase(Locale.ROOT) + (t.equals(tab) ? " *" : ""));
            b.setOnClickListener(v -> { tab = t; showGifts(); });
            tabs.addView(b, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f));
        }
        root.addView(tabs);
        for (Object[] p : PRODUCTS) {
            if (!tab.equals(p[2])) continue;
            button(productId((String) p[0]), (String) p[1], v -> { product = p; quantity = 1; showConfirm(); });
        }
    }

    private void showConfirm() {
        reset();
        int cost = (Integer) product[3];
        int max = (Integer) product[4];
        text(R.id.tv_confirm_title, "Confirmar regalo", 18);
        text(R.id.tv_confirm_recipient, playerId, 16);
        text(R.id.tv_confirm_product, (String) product[1], 16);
        text(R.id.tv_confirm_product_code, (String) product[0], 14);
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        Button minus = new Button(this);
        minus.setId(R.id.btn_qty_minus);
        minus.setText("-");
        minus.setOnClickListener(v -> { quantity = Math.max(quantity - 1, 1); showConfirm(); });
        TextView qty = new TextView(this);
        qty.setId(R.id.tv_quantity);
        qty.setText(String.valueOf(quantity));
        qty.setTextColor(Color.WHITE);
        qty.setGravity(Gravity.CENTER);
        Button plus = new Button(this);
        plus.setId(R.id.btn_qty_plus);
        plus.setText("+");
        plus.setOnClickListener(v -> { quantity = Math.min(quantity + 1, max); showConfirm(); });
        row.addView(minus);
        row.addView(qty, new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f));
        row.addView(plus);
        root.addView(row);
        text(R.id.tv_total_cost, String.valueOf(quantity * cost), 16);
        button(R.id.btn_confirm, "Confirmar envío", v -> commit());
        button(R.id.btn_cancel, "Cancelar", v -> showGifts());
    }

    private void commit() {
        int cost = (Integer) product[3] * quantity;
        String ref = "REF-" + UUID.randomUUID().toString().substring(0, 8).toUpperCase(Locale.ROOT);
        if ("success".equals(outcome) && cost > balance()) {
            showResult(false, ref, "Diamantes insuficientes");
            return;
        }
        if (!"failure".equals(outcome)) prefs.edit().putInt("balance", balance() - cost).apply();
        reset();
        text(R.id.tv_processing, "Procesando...", 16).setGravity(Gravity.CENTER);
        if ("uncertain".equals(outcome)) return;  // se queda procesando: resultado incierto
        new Handler(Looper.getMainLooper()).postDelayed(
            () -> showResult("success".equals(outcome), ref, "Rechazado"), 800);
    }

    private void showResult(boolean ok, String ref, String reason) {
        reset();
        text(R.id.tv_result_title, ok ? "Regalo enviado" : "Envío rechazado", 18);
        text(R.id.tv_result_recipient, playerId, 16);
        text(R.id.tv_result_product_code, (String) product[0], 16);
        text(R.id.tv_result_quantity, String.valueOf(quantity), 16);
        text(R.id.tv_result_ref, ok ? ref : "", 14);
        if (!ok) error(R.id.tv_result_reason, reason);
        button(R.id.btn_done, "Finalizar", v -> { playerId = ""; product = null; showHome(); });
    }

    @Override
    public void onBackPressed() {
        showHome();
    }
}
