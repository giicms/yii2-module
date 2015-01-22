<?php
	extract($data);
	$value = is_array($value) ? json_encode($value) : $value;
	use yii\helpers\Html;
	use yii\widgets\ActiveForm;
?>

<div class="list-group-item col-md-12 col-lg-12" id="value_<?= $unique_id; ?>">
	<div class="row">
		<div class="col-md-2 col-lg-2 col-sm-3">
			<label><?= $name; ?></label>
		</div>
		<div class="col-md-9 col-lg-9 col-sm-7">
			<div id='<?= $unique_id; ?>'>
				<?= @$surround['open']; ?><div id='<?= $unique_id; ?>.div' role="updateFieldDiv" data-id="<?= $unique_id; ?>" data-type="<?= $model->config('current.type'); ?>"><?= htmlentities($value); ?></div><?= @$surround['close']; ?>
				<div class="row">
				<?php $form = ActiveForm::begin(['id' => "value_comment_$unique_id",
						'action' => '/configuration/comment',
						'options' => ['class' => 'form-horizontal'],
						'fieldConfig' => [
								  'inputOptions' => ['class' => 'form-control']
								],
						]);?>
					<div class="col-md-11 col-lg-11 col-sm-10">
					<?php
						echo Html::activeTextInput($model, 'comment', [
							'placeholder' => "Type comment here...",
							'value' => @$comment,
							'class' => 'form-control input-sm col-md-10'
						]);
					?>
					</div>
					<div class="col-md-1 col-lg-1 col-sm-2">
					<?php
						echo Html::activeHiddenInput($model, 'id', array('value' => $id));
						echo Html::submitButton('save', [
							'class' => 'btn btn-primary btn-xs',
							'title' => "Edit $section.$name"
						]);
					?>	
					</div>
				<?php ActiveForm::end(); ?>
				</div>
			</div>
		</div>
		<div class="col-lg-1 col-md-1 col-sm-2">
			<?php $form = ActiveForm::begin(['id' => "update_value_form_$unique_id",
				'action' => '/configuration/update',
				'options' => [
					'class' => 'form-inline'
				],
				'fieldConfig' => [
						  'inputOptions' => ['class' => 'form-control']
						],
				]);
			?>
			<?php
				echo Html::activeHiddenInput($model, 'what', array('value' => 'value'));
				echo Html::activeHiddenInput($model, 'id', array('value' => $id));
				echo Html::activeHiddenInput($model, 'container', array('value' => $container_name));
				echo Html::activeHiddenInput($model, 'name', array('value' => $unique_id));
				echo Html::activeHiddenInput($model, 'value', array('value' => $value,
										    'role' => 'value'));
				echo Html::submitButton('update', [
					'id' => 'update_value',
					'class' => 'btn btn-primary btn-sm',
					'title' => "Edit $id",
					'role' => 'updateFieldButton',
					'data-id' => $id.'.div',
					'data-type' => $model->config('current.type'),
					"data-loading-text" => "Editing..."
				]);
			?>
			<?php ActiveForm::end(); ?>
			<?php 
				$model->setScenario('addValue');
				$form = ActiveForm::begin(['id' => 'delete_value',
					'action' => '/configuration/delete',
					'options' => ['class' => 'form-inline',
					'role' => 'deleteValue'],
					'fieldConfig' => [
						'inputOptions' => ['class' => 'form-control']
					],
				]);
			?>
			<?php
				echo Html::activeHiddenInput($model, 'id', array('value' => $id));
				echo Html::activeHiddenInput($model, 'name', array('value' => $unique_id));
				echo Html::activeHiddenInput($model, 'what', array('value' => 'value'));
				echo Html::activeHiddenInput($model, 'container', array('value' => $container_name));
				echo Html::activeHiddenInput($model, 'div_container', array('value' => $id.'.div'));
				echo Html::submitButton('del', [
					'class' => 'btn btn-danger btn-sm',
					'title' => "Are you sure you want to delete the $unique_id",
					"data-loading-text" => "Deleting..."
				]);
			?>
			<?php ActiveForm::end(); ?>
		</div>
	</div>
</div>