package com.diamantesexpress.giftsim;

import android.app.Activity;
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

import java.util.UUID;

/**
 * DX Gift Simulator: aplicación FICTICIA para validar DiamantesExpress Agent en BlueStacks.
 * No tiene permisos de red ni realiza compras o envíos reales.
 * Pantallas: inicio -> destinatario -> selección de regalo -> confirmación -> resultado.
 * Modo de resultado configurable por intent extra "outcome": success | failure | uncertain.
 */
public class MainActivity extends Activity {
    private static final String[][] PRODUCTS = {
        {"DIAMANTES_100", "100 Diamantes"}, {"DIAMANTES_310", "310 Diamantes"},
        {"DIAMANTES_520", "520 Diamantes"}, {"DIAMANTES_1060", "1060 Diamantes"},
    };
    private LinearLayout root;
    private String playerId = "";
    private String[] product;
    private String outcome = "success";

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        String extra = getIntent().getStringExtra("outcome");
        if (extra != null) outcome = extra;
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 48, 48, 48);
        root.setBackgroundColor(Color.rgb(12, 14, 22));
        setContentView(root);
        showHome();
    }

    private void reset() {
        root.removeAllViews();
        text(R.id.app_title, "DX Gift Simulator", 22);
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

    private Button button(int id, String value, View.OnClickListener listener) {
        Button b = new Button(this);
        b.setId(id);
        b.setText(value);
        b.setAllCaps(false);
        b.setOnClickListener(listener);
        root.addView(b);
        return b;
    }

    private void showHome() {
        reset();
        text(R.id.tv_home, "Simulador de regalos (sin envíos reales)", 16);
        button(R.id.btn_start, "Enviar regalo", v -> showRecipient(null));
    }

    private void showRecipient(String error) {
        reset();
        text(R.id.tv_recipient_label, "ID del jugador destinatario", 16);
        EditText field = new EditText(this);
        field.setId(R.id.field_player_id);
        field.setInputType(InputType.TYPE_CLASS_NUMBER);
        field.setTextColor(Color.BLACK);
        field.setBackgroundColor(Color.rgb(230, 230, 235));
        root.addView(field);
        button(R.id.btn_search, "Buscar jugador", v -> {
            String value = field.getText().toString().trim();
            if (value.matches("[0-9]{5,15}")) {
                playerId = value;
                showGifts();
            } else {
                showRecipient("Jugador no encontrado");
            }
        });
        if (error != null) text(R.id.tv_error, error, 14).setTextColor(Color.rgb(255, 93, 108));
    }

    private void showGifts() {
        reset();
        text(R.id.tv_recipient, "Destinatario: " + playerId, 16);
        int[] ids = {R.id.product_DIAMANTES_100, R.id.product_DIAMANTES_310, R.id.product_DIAMANTES_520,
                     R.id.product_DIAMANTES_1060};
        for (int i = 0; i < PRODUCTS.length; i++) {
            final String[] p = PRODUCTS[i];
            button(ids[i], p[1], v -> { product = p; showConfirm(); });
        }
    }

    private void showConfirm() {
        reset();
        text(R.id.tv_confirm_title, "Confirmar envío", 18);
        text(R.id.tv_confirm_recipient, playerId, 16);
        text(R.id.tv_confirm_product, product[1], 16);
        text(R.id.tv_confirm_product_code, product[0], 14);
        button(R.id.btn_confirm, "Confirmar envío", v -> commit());
        button(R.id.btn_cancel, "Cancelar", v -> showGifts());
    }

    private void commit() {
        reset();
        text(R.id.tv_processing, "Procesando...", 16).setGravity(Gravity.CENTER);
        if ("uncertain".equals(outcome)) return;  // se queda procesando: resultado incierto
        new Handler(Looper.getMainLooper()).postDelayed(() -> showResult("success".equals(outcome)), 800);
    }

    private void showResult(boolean ok) {
        reset();
        text(R.id.tv_result_title, ok ? "Regalo enviado" : "Envío rechazado", 18);
        text(R.id.tv_result_recipient, playerId, 16);
        text(R.id.tv_result_product_code, product[0], 16);
        text(R.id.tv_result_ref, "REF-" + UUID.randomUUID().toString().substring(0, 8).toUpperCase(), 14);
        button(R.id.btn_done, "Finalizar", v -> { playerId = ""; product = null; showHome(); });
    }

    @Override
    public void onBackPressed() {
        showHome();
    }
}
