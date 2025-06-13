package com.novemax.expense.repository;

import com.novemax.expense.model.Group;
import java.util.List;
import java.util.Optional;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.UUID;

public interface GroupRepository extends JpaRepository<Group, UUID> {

  List<Group> findByUsers_Email(String email);

  List<Group> findByUsers_EmailAndDeletedFalse(String userEmail);

  Optional<Group> findByIdAndUsers_EmailAndDeletedFalse(UUID id, String userEmail);

  Optional<Group> findByIdAndDeletedFalse(UUID groupId);
}
