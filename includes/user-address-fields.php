<h3><?php _e("Postiosoite", "blank"); ?></h3>

<table class="form-table">
<tr>
<th><label for="address"><?php _e("Osoite"); ?></label></th>
<td>
<input type="text" name="address" id="address" value="<?php echo esc_attr( get_the_author_meta( 'address', $user->ID ) ); ?>" class="regular-text" /><br />
<span class="description"><?php _e("Syötä postiosoite"); ?></span>
</td>
</tr>
<tr>
<th><label for="city"><?php _e("Kaupunki"); ?></label></th>
<td>
<input type="text" name="city" id="city" value="<?php echo esc_attr( get_the_author_meta( 'city', $user->ID ) ); ?>" class="regular-text" /><br />
<span class="description"><?php _e("Syötä postipaikkakunta."); ?></span>
</td>
</tr>
<tr>
<th><label for="postalcode"><?php _e("Postinumero"); ?></label></th>
<td>
<input type="text" name="postalcode" id="postalcode" value="<?php echo esc_attr( get_the_author_meta( 'postalcode', $user->ID ) ); ?>" class="regular-text" /><br />
<span class="description"><?php _e("Syötä postinumero."); ?></span>
</td>
</tr>
</table>