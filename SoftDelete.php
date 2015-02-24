<?php
/**
 * @link https://github.com/sensa-ua/yii2-softdelete
 * @copyright Copyright (c) 2015 Oleg Pshenychnyi
 * @license http://opensource.org/licenses/BSD-3-Clause
 */
    
    namespace common\behaviors;

    use yii\db\ActiveRecord;
    use yii\db\StaleObjectException;
    use yii\base\ModelEvent;

    /**
    * SoftDeleteBehavior implements pattern of soft deletion based on attribute change.
    * It provide both options - softDelete and softRestore as well as isDeleted 
    * It expose Before/After_Soft_Delete/Restore events same way like ActiveRecord does
    * It also secure data consistency with Transaction mechanizm
    * @author OlegPshenychnyi <op@sensa.com.ua>
    */
    class SoftDelete extends \yii\base\Behavior
    {
        /**
        * @event ModelEvent an event that is triggered before deleting a record.
        * You may set [[ModelEvent::isValid]] to be false to stop the deletion.
        */
        const EVENT_BEFORE_SOFT_DELETE = 'beforeSoftDelete';

        /**
        * @event Event an event that is triggered after a record is deleted.
        */
        const EVENT_AFTER_SOFT_DELETE = 'afterSoftDelete';

        /**
        * @event ModelEvent an event that is triggered before restoreation of a record.
        * You may set [[ModelEvent::isValid]] to be false to stop the deletion.
        */
        const EVENT_BEFORE_SOFT_RESTORE = 'beforeSoftRestore';

        /**
        * @event Event an event that is triggered after a record is restored.
        */
        const EVENT_AFTER_SOFT_RESTORE = 'afterSoftRestore';

        /**
        * @var string|array List of attributes to be changed on soft deletion.
        * Default value of this property is `['deleted', 'deleted_at' => time()]`,
        * which means that `deleted` will receive default behavior value,
        * and 'deleted_at' will be updated to current timestamp.
        */
        public $attributes = [];

        /**
        * @var string the attribute that determines whether row is soft deleted
        */
        public $deletedAttribute = 'deleted';

        /**
        * @inheritdoc
        */
        public function init()
        {
            parent::init();

            if (empty($this->attributes)) {
                $this->attributes=[$this->deletedAttribute];
            }
            
            if (!is_array($this->attributes)) {
                $this->attributes = [$this->attributes];
            }
        }

        /**
        * This method is invoked before soft deleting a record.
        * The default implementation raises the [[EVENT_BEFORE_SOFT_DELETE]] event.
        * When overriding this method, make sure you call the parent implementation like the following:
        *
        * ~~~
        * protected function beforeSoftDelete()
        * {
        *     if (parent::beforeSoftDelete()) {
        *         // ...custom code here...
        *         return true;
        *     } else {
        *         return false;
        *     }
        * }
        * ~~~
        *
        * @return boolean whether the record should be deleted. Defaults to true.
        */
        protected function beforeSoftDelete() 
        {
            $event = new ModelEvent;

            $this->owner->trigger(self::EVENT_BEFORE_SOFT_DELETE, $event);

            return $event->isValid;
        }

        /**
        * This method is invoked after soft deleting a record.
        * The default implementation raises the [[EVENT_AFTER_SOFT_DELETE]] event.
        * You may override this method to do postprocessing after the record is deleted.
        * Make sure you call the parent implementation so that the event is raised properly.
        */
        protected function afterSoftDelete() 
        {
            $this->owner->trigger(self::EVENT_AFTER_SOFT_DELETE);
        }   
        
         /**
        * This method is invoked before soft deleting a record.
        * The default implementation raises the [[EVENT_BEFORE_SOFT_DELETE]] event.
        * When overriding this method, make sure you call the parent implementation like the following:
        *
        * ~~~
        * protected function beforeSoftDelete()
        * {
        *     if (parent::beforeSoftDelete()) {
        *         // ...custom code here...
        *         return true;
        *     } else {
        *         return false;
        *     }
        * }
        * ~~~
        *
        * @return boolean whether the record should be deleted. Defaults to true.
        */
        protected function beforeSoftRestore() 
        {
            $event = new ModelEvent;

            $this->owner->trigger(self::EVENT_BEFORE_SOFT_RESTORE, $event);

            return $event->isValid;
        }

        /**
        * This method is invoked after soft deleting a record.
        * The default implementation raises the [[EVENT_AFTER_SOFT_DELETE]] event.
        * You may override this method to do postprocessing after the record is deleted.
        * Make sure you call the parent implementation so that the event is raised properly.
        */
        protected function afterSoftRestore() 
        {
            $this->owner->trigger(self::EVENT_AFTER_SOFT_RESTORE);
        }  

        /**
        * @param ModelEvent $event
        * @return boolean
        */
        public function softDelete()
        {
            $model = $this->owner;

            if ($model->isNewRecord) {
                throw new \yii\db\Exception('The object you\'re going to delete is not saved yet.'); 
            }

            $updatedValues = [];

            foreach ($this->attributes as $key => $attribute) 
            {
                if (is_int($key)) {
                    $value = /*$this->value*/1;
                } else {
                    $value = $attribute;
                    $attribute = $key;
                }

                //$value = $this->getValue($value, $event);

                if ($model->$attribute === $value) {
                    continue;
                }

                $updatedValues[$attribute]=$model->$attribute=$value;
                //$model->setOldAttribute($attribute, $model->$attribute);
            }

            if (empty($updatedValues)) {
                return null;
            }

            // we do not check the return value of deleteAll() because it's possible
            // the record is already deleted in the database and thus the method will return 0
            if (null !== $lock = $model->optimisticLock()) {
                $condition[$lock] = $model->$lock;
            }

            $transaction = $model->getDb()->beginTransaction();

            try 
            {
                if (false !== $result=$this->beforeSoftDelete()) 
                {
                    if(false !== $result=$model->updateAttributes($updatedValues)){
                        $this->afterSoftDelete(); 
                    }

                    if ($result === false) {
                        $transaction->rollBack();
                    } else {
                        $transaction->commit();
                    }
                }

            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($lock !== null && !$result) {
                throw new StaleObjectException('The object being deleted is outdated.');
            }

            return $result;
        }
        
        public function getIsDeleted($trowException=true)
        {
            $model = $this->owner;

            if (!$model->isNewRecord) {

                foreach ($this->attributes as $key => $attribute) 
                {
                    if (is_int($key)) {
                        return $model->$attribute;
                    }
                }
            }
                
            if($trowException){
                throw new \yii\db\Exception('The object is not in correct state.'); 
            } else {
                return false;
            }
        }
        
         /**
        * @param ModelEvent $event
        * @return boolean
        */
        public function softRestore()
        {
            $model = $this->owner;

            if (!$model->isDeleted) {
                throw new \yii\db\Exception('The object you\'re going to restore has not deleted yet.'); 
            }

            $updatedValues = [];

            foreach ($this->attributes as $key => $attribute) 
            {
                if (is_int($key)) {
                    $value = /*$this->value*/ 0;
                } else {
                    $value = $attribute;
                    $attribute = $key;
                }

                //$value = $this->getValue($value, $event);

                if ($model->$attribute === $value) {
                    continue;
                }

                $updatedValues[$attribute]=$model->$attribute=$value;
                //$model->setOldAttribute($attribute, $model->$attribute);
            }

            if (empty($updatedValues)) {
                return null;
            }

            // we do not check the return value of deleteAll() because it's possible
            // the record is already deleted in the database and thus the method will return 0
            if (null !== $lock = $model->optimisticLock()) {
                $condition[$lock] = $model->$lock;
            }

            $transaction = $model->getDb()->beginTransaction();

            try 
            {
                if (false !== $result=$this->beforeSoftRestore()) 
                {
                    if(false !== $result=$model->updateAttributes($updatedValues)){
                        $this->afterSoftRestore(); 
                    }

                    if ($result === false) {
                        $transaction->rollBack();
                    } else {
                        $transaction->commit();
                    }
                }

            } catch (\Exception $e) {
                $transaction->rollBack();
                throw $e;
            }

            if ($lock !== null && !$result) {
                throw new StaleObjectException('The object being deleted is outdated.');
            }

            return $result;
        }
    }

?>